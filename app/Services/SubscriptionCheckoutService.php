<?php

namespace App\Services;

use App\Contracts\MailAdapterInterface;
use App\Contracts\PaymentAdapterInterface;
use App\Mail\SubscriptionPaymentMail;
use App\Models\Subscription;
use App\Models\User;
use App\Repository\PaymentRepository;
use App\Repository\SubscriptionPlanRepository;
use App\Repository\SubscriptionRepository;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class SubscriptionCheckoutService
{
    private const RENEWAL_WINDOW_DAYS = 3;

    public function __construct(
        private readonly SubscriptionPlanRepository $planRepo,
        private readonly SubscriptionRepository $subscriptionRepo,
        private readonly PaymentRepository $paymentRepo,
        private readonly PaymentAdapterInterface $paymentAdapter,
        private readonly MailAdapterInterface $mailer
    ) {}

    public function createCheckout(User $owner, string $planUuid): array
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

        $subscription = $this->subscriptionRepo->createPending($owner->user_id, $plan);

        $amount = (int) round($plan->price * 100); // stored in centavos, same convention as before

        try {
            $checkout = $this->paymentAdapter->createCheckoutSession([
                'amount'      => $amount,
                'plan_name'   => $plan->sub_name,
                'description' => "Subscription - {$plan->sub_name}",
                'success_url' => config('services.paypal.success_url'),
                'cancel_url'  => config('services.paypal.cancel_url'),
                'metadata'    => [
                    'subscription_uuid' => $subscription->uuid,
                    'owner_uuid'        => $owner->uuid,
                    'plan_uuid'         => $plan->uuid,
                ],
            ]);
        } catch (\Throwable $e) {
            $this->subscriptionRepo->markFailed($subscription);

            Log::channel('owner')->error('Checkout order creation failed.', [
                'owner_uuid'        => $owner->uuid,
                'subscription_uuid' => $subscription->uuid,
                'error'             => $e->getMessage(),
            ]);

            return ['success' => false, 'message' => 'Unable to start checkout. Please try again.'];
        }

        // PayPal Order id is our tracking id — matched against in the webhook.
        $this->paymentRepo->create([
            'user_id'                => $owner->user_id,
            'payable_type'           => Subscription::class,
            'payable_id'             => $subscription->sub_id,
            'amount'                 => $amount,
            'payment_method_type'    => 'paypal_order',
            'gateway_transaction_id' => $checkout['id'],
            'status'                 => 'pending',
        ]);

        Log::channel('owner')->info('PayPal checkout order created.', [
            'owner_uuid'        => $owner->uuid,
            'subscription_uuid' => $subscription->uuid,
            'plan_uuid'         => $plan->uuid,
            'order_id'          => $checkout['id'],
        ]);

        return [
            'success'           => true,
            'message'           => 'Checkout order created. Open the link below to approve payment.',
            'checkout_url'      => $checkout['checkout_url'],
            'subscription_uuid' => $subscription->uuid,
        ];
    }

    /**
     * Called by PaymentWebhookController after signature verification.
     * $resource is the PayPal webhook 'resource' object.
     */
    public function handleWebhook(string $eventType, array $resource): void
    {
        match ($eventType) {
            // Order approved by the buyer — capture the funds now.
            'CHECKOUT.ORDER.APPROVED' => $this->handleOrderApproved($resource),
            'PAYMENT.CAPTURE.DENIED'  => $this->handleCaptureDenied($resource),
            default                    => null,
        };
    }

    private function handleOrderApproved(array $resource): void
    {
        $orderId = $resource['id'] ?? null;

        if (! $orderId) {
            Log::channel('admin')->warning('PayPal order.approved — no order id present in resource.');

            return;
        }

        $payment = $this->paymentRepo->findByGatewayTransactionId($orderId);

        if (! $payment) {
            Log::channel('admin')->warning('PayPal order.approved — no matching payment record found.', [
                'order_id' => $orderId,
            ]);

            return;
        }

        if ($payment->status === 'succeeded') {
            Log::channel('admin')->info('PayPal order.approved — already processed, ignoring duplicate.', [
                'order_id' => $orderId,
            ]);

            return;
        }

        try {
            $capture = app(\App\Contracts\PaymentAdapterInterface::class)->captureOrder($orderId);
        } catch (\Throwable $e) {
            Log::channel('admin')->error('PayPal order capture failed.', [
                'order_id' => $orderId,
                'error'    => $e->getMessage(),
            ]);

            return;
        }

        $captureStatus = $capture['purchase_units'][0]['payments']['captures'][0]['status'] ?? null;

        if ($captureStatus !== 'COMPLETED') {
            Log::channel('admin')->warning('PayPal capture did not complete.', [
                'order_id' => $orderId,
                'status'   => $captureStatus,
            ]);

            $this->paymentRepo->markFailed($payment, 'paypal');

            return;
        }

        $this->paymentRepo->markSucceeded($payment, 'paypal');

        $subscription = $payment->payable;

        if (! $subscription instanceof Subscription) {
            Log::channel('admin')->warning('PayPal order.approved — payable is not a Subscription.', [
                'order_id' => $orderId,
            ]);

            return;
        }

        $subscription = $this->subscriptionRepo->activate($subscription);
        $this->subscriptionRepo->cancelOtherActiveSubscriptions($subscription->user_id, $subscription->sub_id);

        $owner = $subscription->user;

        Log::channel('admin')->info('Subscription activated via PayPal order capture.', [
            'subscription_uuid' => $subscription->uuid,
            'order_id'          => $orderId,
        ]);

        if ($owner) {
            $this->mailer->sendMailable($owner->email, new SubscriptionPaymentMail(
                ownerName: $owner->firstname ?? $owner->username ?? 'there',
                planName: $subscription->plan->sub_name,
                status: 'active',
                amount: number_format($subscription->plan->price, 2),
                endDate: $subscription->end_date?->format('F j, Y'),
            ));

            Log::channel('owner')->info('Subscription payment success email sent.', [
                'owner_uuid'        => $owner->uuid,
                'subscription_uuid' => $subscription->uuid,
            ]);
        }
    }

    private function handleCaptureDenied(array $resource): void
    {
        $orderId = $resource['supplementary_data']['related_ids']['order_id'] ?? null;

        if (! $orderId) {
            Log::channel('admin')->warning('PayPal capture.denied — no order_id present.');

            return;
        }

        $payment = $this->paymentRepo->findByGatewayTransactionId($orderId);

        if (! $payment) {
            Log::channel('admin')->warning('PayPal capture.denied — no matching payment record found.', [
                'order_id' => $orderId,
            ]);

            return;
        }

        if ($payment->status === 'failed') {
            return;
        }

        $this->paymentRepo->markFailed($payment, 'paypal');

        $subscription = $payment->payable;

        if (! $subscription instanceof Subscription) {
            return;
        }

        $subscription = $this->subscriptionRepo->markFailed($subscription);
        $owner        = $subscription->user;

        Log::channel('admin')->info('Subscription payment denied via PayPal webhook.', [
            'subscription_uuid' => $subscription->uuid,
            'order_id'          => $orderId,
        ]);

        if ($owner) {
            $this->mailer->sendMailable($owner->email, new SubscriptionPaymentMail(
                ownerName: $owner->firstname ?? $owner->username ?? 'there',
                planName: $subscription->plan->sub_name,
                status: 'failed',
            ));
        }
    }
}