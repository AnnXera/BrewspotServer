<?php

namespace App\Services;

use App\Contracts\PaymentAdapterInterface;
use App\Models\User;
use App\Repository\SubscriptionPlanRepository;
use App\Repository\SubscriptionRepository;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

class NativeSubscriptionService
{
    public function __construct(
        private readonly SubscriptionPlanRepository $planRepo,
        private readonly SubscriptionRepository $subscriptionRepo,
        private readonly PaymentAdapterInterface $paymentAdapter
    ) {}

    /**
     * Creates a recurring PayPal subscription and returns the approve_url
     * the frontend should redirect the owner to. Activation happens later
     * via the BILLING.SUBSCRIPTION.ACTIVATED webhook.
     */
    public function createSubscription(User $owner, string $planUuid): array
    {
        $plan = $this->planRepo->findByUuid($planUuid);

        if (! $plan) {
            return ['success' => false, 'message' => 'Subscription plan not found.'];
        }

        try {
            if (! $plan->paypal_plan_id) {
                $paypalPlan = $this->paymentAdapter->createPlan([
                    'name'        => $plan->sub_name,
                    'description' => $plan->description ?? $plan->sub_name,
                    'amount'      => (int) round($plan->price * 100),
                ]);

                $plan = $this->planRepo->syncPayPalPlanId($plan, $paypalPlan['id']);
            }

            $paypalSubscription = $this->paymentAdapter->createSubscription($plan->paypal_plan_id);

            $subscription = $this->subscriptionRepo->createPendingWithPayPal(
                $owner->user_id,
                $plan,
                $paypalSubscription['id']
            );

            $approveLink = collect($paypalSubscription['links'] ?? [])->firstWhere('rel', 'approve')['href'] ?? null;

            Log::channel('owner')->info('PayPal native subscription initiated.', [
                'owner_uuid'             => $owner->uuid,
                'subscription_uuid'      => $subscription->uuid,
                'paypal_subscription_id' => $paypalSubscription['id'],
            ]);

            return [
                'success'           => true,
                'message'           => 'Subscription created. Approve it via the link to activate.',
                'subscription_uuid' => $subscription->uuid,
                'approve_url'       => $approveLink,
            ];
        } catch (\Throwable $e) {
            Log::channel('owner')->error('PayPal native subscription creation failed.', [
                'owner_uuid' => $owner->uuid,
                'plan_uuid'  => $planUuid,
                'error'      => $e->getMessage(),
            ]);

            return [
                'success' => false,
                'message' => config('app.debug')
                    ? $e->getMessage()
                    : 'Unable to create subscription. Please try again.',
            ];
        }
    }

    /**
     * Called by PaymentWebhookController for BILLING.SUBSCRIPTION.* events.
     */
    public function handleWebhookEvent(string $eventType, array $resource): void
    {
        $paypalSubscriptionId = $resource['id'] ?? null;

        if (! $paypalSubscriptionId) {
            Log::channel('admin')->warning('PayPal subscription webhook — no subscription id present.', [
                'event_type' => $eventType,
            ]);

            return;
        }

        $subscription = $this->subscriptionRepo->findByPayPalSubscriptionId($paypalSubscriptionId);

        if (! $subscription) {
            Log::channel('admin')->warning('PayPal subscription webhook — no matching local subscription.', [
                'event_type'             => $eventType,
                'paypal_subscription_id' => $paypalSubscriptionId,
            ]);

            return;
        }

        match ($eventType) {
            'BILLING.SUBSCRIPTION.ACTIVATED'      => $this->handleActivated($subscription, $resource),
            'BILLING.SUBSCRIPTION.PAYMENT.FAILED' => $this->subscriptionRepo->markPastDue($subscription),
            'BILLING.SUBSCRIPTION.SUSPENDED',
            'BILLING.SUBSCRIPTION.CANCELLED',
            'BILLING.SUBSCRIPTION.EXPIRED'        => $this->subscriptionRepo->markCancelledByPayPal($subscription),
            default                                 => Log::channel('admin')->info('PayPal subscription webhook — unhandled event type.', ['event_type' => $eventType]),
        };
    }

    private function handleActivated($subscription, array $resource): void
    {
        $nextBilling = isset($resource['billing_info']['next_billing_time'])
            ? Carbon::parse($resource['billing_info']['next_billing_time'])
            : null;

        $subscription = $this->subscriptionRepo->activateFromPayPal($subscription, $nextBilling);

        Log::channel('admin')->info('PayPal subscription activated.', [
            'subscription_uuid' => $subscription->uuid,
        ]);
    }
}