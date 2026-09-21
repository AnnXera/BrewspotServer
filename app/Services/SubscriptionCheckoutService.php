<?php

namespace App\Services;

use App\Services\PaymentGatewayManager;
use App\Models\Subscription;
use App\Models\User;
use App\Repository\PaymentRepository;
use App\Repository\SubscriptionPlanRepository;
use App\Repository\SubscriptionRepository;
use Illuminate\Support\Carbon;
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
    private const RENEWAL_WINDOW_DAYS = 3;

    public function __construct(
        private readonly SubscriptionPlanRepository $planRepo,
        private readonly SubscriptionRepository $subscriptionRepo,
        private readonly PaymentRepository $paymentRepo,
        private readonly PaymentGatewayManager $paymentManager
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

        if ($activeSamePlan && $activeSamePlan->end_date) {
            $renewalWindowStart = $activeSamePlan->end_date->copy()->subDays(self::RENEWAL_WINDOW_DAYS);

            if (Carbon::now()->lt($renewalWindowStart)) {
                Log::channel('owner')->warning('Checkout blocked — plan already active and not yet within renewal window.', [
                    'owner_uuid'       => $owner->uuid,
                    'plan_uuid'        => $plan->uuid,
                    'current_end_date' => $activeSamePlan->end_date->toDateString(),
                ]);

                return [
                    'success' => false,
                    'message' => "You already have an active {$plan->sub_name} subscription that runs until {$activeSamePlan->end_date->format('F j, Y')}. You can renew starting " . self::RENEWAL_WINDOW_DAYS . ' days before it expires.',
                ];
            }
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
}
