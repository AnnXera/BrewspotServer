<?php

namespace App\Services;

use App\Contracts\MailAdapterInterface;
use App\Mail\SubscriptionPlanChangedMail;
use App\Services\PaymentGatewayManager;
use App\Models\Subscription;
use App\Models\User;
use App\Repository\PaymentRepository;
use App\Repository\SubscriptionPlanRepository;
use App\Repository\SubscriptionRepository;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Starts a PayMongo checkout for a subscription.
 *
 * This service only opens the checkout session and records a pending payment. Confirming
 * the payment and activating the subscription happens in PayMongoWebhookController, since
 * the gateway is the only trustworthy source for whether money actually moved.
 */
class SubscriptionCheckoutService
{
    public function __construct(
        private readonly SubscriptionPlanRepository $planRepo,
        private readonly SubscriptionRepository $subscriptionRepo,
        private readonly PaymentRepository $paymentRepo,
        private readonly PaymentGatewayManager $paymentManager,
        private readonly MailAdapterInterface $mailer
    ) {}

    public function createCheckout(User $owner, string $planUuid, string $billingCycle = 'monthly', string $gateway = 'paymongo'): array
    {
        $plan = $this->planRepo->findByUuid($planUuid);

        if (! $plan) {
            Log::channel('owner')->warning('Checkout blocked — plan not found.', [
                'owner_uuid' => $owner->uuid,
                'plan_uuid'  => $planUuid,
            ]);

            return ['success' => false, 'message' => 'Subscription plan not found.'];
        }

        if (stripos($plan->sub_name, 'daily') !== false) {
            $billingCycle = 'daily';
        }

        $activeSamePlan = $this->subscriptionRepo->findActiveByUserAndPlan($owner->user_id, $plan->sub_plan_id);

        if (! $plan->is_active && ! $activeSamePlan) {
            Log::channel('owner')->warning('Checkout blocked — plan is disabled and owner does not already hold it.', [
                'owner_uuid' => $owner->uuid,
                'plan_uuid'  => $plan->uuid,
            ]);

            return ['success' => false, 'message' => 'This subscription plan is no longer available.'];
        }

        $isTrial             = Str::contains(strtolower($plan->sub_name), 'trial');
        $hasSubscribedBefore = $this->subscriptionRepo->hasAnyByUserId($owner->user_id);

        if ($isTrial && $hasSubscribedBefore) {
            Log::channel('owner')->warning('Checkout blocked — trial already used.', [
                'owner_uuid' => $owner->uuid,
                'plan_uuid'  => $plan->uuid,
            ]);

            return [
                'success' => false,
                'message' => 'You have already used your free trial and are not eligible to use it again.',
            ];
        }

        // Nothing is chargeable while the owner still has paid days left — not a renewal of
        // the plan they hold, and not an upgrade or downgrade either. A plan change is booked
        // through schedulePlanChange() and paid for when the term runs out, so an owner is
        // never asked for money twice over the same stretch of time.
        $current = $this->subscriptionRepo->findCurrentByUserId($owner->user_id);

        if ($current && ! $this->isUnpaidTerm($current) && ! $current->isRenewalOpen()) {
            $runsUntil   = $current->end_date->format('F j, Y');
            $renewalOpens = $current->renewalOpensAt()->format('F j, Y');
            $isSamePlan  = $current->sub_plan_id === $plan->sub_plan_id;

            Log::channel('owner')->warning('Checkout blocked — paid term still running.', [
                'owner_uuid'       => $owner->uuid,
                'plan_uuid'        => $plan->uuid,
                'current_plan'     => $current->plan?->sub_name,
                'current_end_date' => $current->end_date->toDateString(),
                'renewal_opens'    => $renewalOpens,
                'same_plan'        => $isSamePlan,
            ]);

            return [
                'success' => false,
                'message' => $isSamePlan
                    ? "You already have an active {$plan->sub_name} subscription that runs until {$runsUntil}. You can renew it on {$renewalOpens}."
                    : "Your {$current->plan?->sub_name} runs until {$runsUntil}. Schedule the {$plan->sub_name} instead — you will pay for it on {$renewalOpens}, when your current plan runs out.",
            ];
        }

        // Pick the correct price based on billing cycle
        $price = $billingCycle === 'yearly'
            ? ($plan->yearly_price ?? $plan->price)
            : $plan->price;

        $subscription = $this->subscriptionRepo->createPending($owner->user_id, $plan, $billingCycle);

        $amount = (int) round($price * 100); // stored in centavos

        try {
            $checkout = $this->paymentManager->gateway($gateway)->createCheckoutSession([
                'amount'      => $amount,
                'plan_name'   => $plan->sub_name,
                'description' => "Subscription - {$plan->sub_name} ({$billingCycle})",
                'success_url' => config('services.paymongo.success_url'),
                // Routed through the API so an abandoned checkout is marked cancelled
                // before the owner is redirected on to the frontend.
                'cancel_url'  => url('/api/payment/cancel'),
                'metadata'    => [
                    'subscription_uuid' => $subscription->uuid,
                    'owner_uuid'        => $owner->uuid,
                    'plan_uuid'         => $plan->uuid,
                    'billing_cycle'     => $billingCycle,
                    'owner_name'        => trim(($owner->firstname ?? '') . ' ' . ($owner->lastname ?? '')) ?: ($owner->username ?? 'Cafe Owner'),
                    'owner_email'       => $owner->email,
                ],
            ]);
        } catch (\Throwable $e) {
            $this->subscriptionRepo->markFailed($subscription);

            Log::channel('owner')->error('Checkout session creation failed.', [
                'owner_uuid'        => $owner->uuid,
                'subscription_uuid' => $subscription->uuid,
                'gateway'           => $gateway,
                'error'             => $e->getMessage(),
            ]);

            return ['success' => false, 'message' => 'Unable to start checkout. Please try again.'];
        }

        // The checkout session id is our tracking id — matched against in the webhook.
        $this->paymentRepo->create([
            'user_id'                => $owner->user_id,
            'payable_type'           => Subscription::class,
            'payable_id'             => $subscription->sub_id,
            'amount'                 => $amount,
            'payment_method_type'    => 'paymongo_checkout',
            'gateway_transaction_id' => $checkout['id'],
            'status'                 => 'pending',
        ]);

        Log::channel('owner')->info('Checkout session created.', [
            'owner_uuid'         => $owner->uuid,
            'subscription_uuid'  => $subscription->uuid,
            'plan_uuid'          => $plan->uuid,
            'billing_cycle'      => $billingCycle,
            'gateway'            => $gateway,
            'checkout_session_id' => $checkout['id'],
        ]);

        return [
            'success'           => true,
            'message'           => 'Checkout session created. Open the link below to complete payment.',
            'checkout_url'      => $checkout['checkout_url'],
            'subscription_uuid' => $subscription->uuid,
        ];
    }

    /**
     * Book a plan change for the end of the owner's current term.
     *
     * Plan changes are never charged mid-term: the owner keeps the days they already paid
     * for, and the booked plan is what their renewal reminder offers them. Two cases fall
     * out of that rather than being special-cased away:
     *
     *  - Choosing the plan they are already on means "cancel my change", so the booking is
     *    dropped instead of a pointless change being recorded.
     *  - An owner on a free trial has no paid days to protect, so there is nothing to wait
     *    for — they are sent straight to checkout via `requires_checkout`.
     *  - An owner whose term has reached its grace day has no paid days left either, so
     *    their choice is paid for now rather than booked — also `requires_checkout`.
     */
    public function schedulePlanChange(User $owner, string $planUuid, string $billingCycle = 'monthly'): array
    {
        $plan = $this->planRepo->findByUuid($planUuid);

        if (! $plan) {
            return ['success' => false, 'message' => 'Subscription plan not found.'];
        }

        if (stripos($plan->sub_name, 'daily') !== false) {
            $billingCycle = 'daily';
        }

        $current = $this->subscriptionRepo->findCurrentByUserId($owner->user_id);

        // No running subscription — there is no term to wait for, so this is a first purchase.
        if (! $current) {
            return [
                'success'           => false,
                'requires_checkout' => true,
                'message'           => 'You do not have an active subscription. Please complete checkout instead.',
            ];
        }

        if ($this->isUnpaidTerm($current)) {
            return [
                'success'           => true,
                'requires_checkout' => true,
                'message'           => 'Your current plan is a free trial, so you can switch straight away.',
            ];
        }

        // The term is on its grace day: there are no paid days left to protect, so the owner
        // pays for the plan they picked now instead of parking it for later.
        if ($current->isRenewalOpen()) {
            return [
                'success'           => true,
                'requires_checkout' => true,
                'message'           => 'Your current term ends today, so you can pay for your new plan now.',
            ];
        }

        // Picking the plan and cycle they already hold means "cancel my scheduled change".
        if ($current->sub_plan_id === $plan->sub_plan_id && $current->billing_cycle === $billingCycle) {
            if (! $current->pending_sub_plan_id) {
                return [
                    'success' => true,
                    'message' => "You are already on the {$plan->sub_name}. Nothing has changed.",
                ];
            }

            $this->subscriptionRepo->clearPendingChange($current);

            Log::channel('owner')->info('Scheduled plan change cancelled.', [
                'owner_uuid'        => $owner->uuid,
                'subscription_uuid' => $current->uuid,
            ]);

            return [
                'success' => true,
                'message' => "Your scheduled plan change has been cancelled. You will stay on the {$plan->sub_name}.",
            ];
        }

        if (! $plan->is_active) {
            return ['success' => false, 'message' => 'This subscription plan is no longer available.'];
        }

        if (Str::contains(strtolower($plan->sub_name), 'trial')) {
            return ['success' => false, 'message' => 'The trial plan cannot be scheduled as a plan change.'];
        }

        $this->subscriptionRepo->schedulePlanChange($current, $plan, $billingCycle);

        Log::channel('owner')->info('Plan change scheduled for end of term.', [
            'owner_uuid'        => $owner->uuid,
            'subscription_uuid' => $current->uuid,
            'pending_plan_uuid' => $plan->uuid,
            'pending_cycle'     => $billingCycle,
            'takes_effect_on'   => $current->end_date?->toDateString(),
        ]);

        $effective = $current->end_date
            ? $current->end_date->format('F j, Y')
            : 'the end of your current term';

        $payableFrom = $current->renewalOpensAt()?->format('F j, Y');

        // Written confirmation of the booking. Gateway-managed subscriptions bill themselves;
        // everyone else is told up front that they will have to pay, and when.
        $this->mailer->sendMailable($owner->email, new SubscriptionPlanChangedMail(
            ownerName: $owner->firstname ?? $owner->username ?? 'there',
            newPlanName: $plan->sub_name,
            effectiveDate: $effective,
            payableFrom: $current->gateway_subscription_id ? null : ($payableFrom ?? $effective),
        ));

        $message = "Your switch to the {$plan->sub_name} is scheduled for {$effective}. You keep your current plan until then, and nothing has been charged.";

        if ($payableFrom) {
            $message .= " You can pay for it from {$payableFrom}.";
        }

        return [
            'success' => true,
            'message' => $message,
        ];
    }

    /**
     * Whether the owner's current term was given rather than bought.
     *
     * Deferring a plan change exists to protect days the owner has already paid for. A
     * trial has none, so there is nothing to defer.
     */
    private function isUnpaidTerm(Subscription $subscription): bool
    {
        if ($subscription->billing_cycle === 'trial') {
            return true;
        }

        $plan = $subscription->plan;

        return $plan
            && (Str::contains(strtolower($plan->sub_name), 'trial') || (float) $plan->price <= 0);
    }
}
