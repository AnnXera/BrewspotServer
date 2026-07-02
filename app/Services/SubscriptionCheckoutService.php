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
        $plan = $this->planRepo->findActiveByUuid($planUuid);

        if (! $plan) {
            Log::channel('owner')->warning('Checkout blocked — plan not found or inactive.', [
                'owner_uuid' => $owner->uuid,
                'plan_uuid'  => $planUuid,
            ]);

            return ['success' => false, 'message' => 'Subscription plan not found.'];
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

        $activeSamePlan = $this->subscriptionRepo->findActiveByUserAndPlan($owner->user_id, $plan->sub_plan_id);

        if ($activeSamePlan && $activeSamePlan->end_date) {
            $renewalWindowStart = $activeSamePlan->end_date->copy()->subDays(self::RENEWAL_WINDOW_DAYS);

            if (Carbon::now()->lt($renewalWindowStart)) {
                Log::channel('owner')->warning('Checkout blocked — plan already active and not yet within renewal window.', [
                    'owner_uuid'         => $owner->uuid,
                    'plan_uuid'          => $plan->uuid,
                    'current_end_date'   => $activeSamePlan->end_date->toDateString(),
                ]);

                return [
                    'success' => false,
                    'message' => "You already have an active {$plan->sub_name} subscription that runs until {$activeSamePlan->end_date->format('F j, Y')}. You can renew starting " . self::RENEWAL_WINDOW_DAYS . ' days before it expires.',
                ];
            }
        }

        $subscription = $this->subscriptionRepo->createPending($owner->user_id, $plan);

        $amount = (int) round($plan->price * 100); // PayMongo expects the smallest currency unit (centavos)

        try {
            $checkout = $this->paymentAdapter->createCheckoutSession([
                'amount'      => $amount,
                'plan_name'   => $plan->sub_name,
                'description' => "Subscription - {$plan->sub_name}",
                'success_url' => config('services.paymongo.success_url'),
                'cancel_url'  => config('services.paymongo.cancel_url'),
                'metadata'    => [
                    'subscription_uuid' => $subscription->uuid,
                    'owner_uuid'        => $owner->uuid,
                    'plan_uuid'         => $plan->uuid,
                ],
            ]);
        } catch (\Throwable $e) {
            $this->subscriptionRepo->markFailed($subscription);

            Log::channel('owner')->error('Checkout session creation failed.', [
                'owner_uuid'        => $owner->uuid,
                'subscription_uuid' => $subscription->uuid,
                'error'             => $e->getMessage(),
            ]);

            return ['success' => false, 'message' => 'Unable to start checkout. Please try again.'];
        }

        $trackingId = $checkout['payment_intent_id'] ?? $checkout['id'];

        $this->paymentRepo->create([
            'user_id'                => $owner->user_id,
            'payable_type'           => Subscription::class,
            'payable_id'             => $subscription->sub_id,
            'amount'                 => $amount,
            'payment_method_type'    => 'checkout_session',
            'gateway_transaction_id' => $trackingId,
            'status'                 => 'pending',
        ]);

        Log::channel('owner')->info('Checkout session created.', [
            'owner_uuid'          => $owner->uuid,
            'subscription_uuid'   => $subscription->uuid,
            'plan_uuid'           => $plan->uuid,
            'checkout_session_id' => $checkout['id'],
            'payment_intent_id'   => $checkout['payment_intent_id'] ?? null,
        ]);

        return [
            'success'           => true,
            'message'           => 'Checkout session created. Open the link below to complete payment.',
            'checkout_url'      => $checkout['checkout_url'],
            'subscription_uuid' => $subscription->uuid,
        ];
    }

    public function handleWebhook(string $rawPayload, ?string $signatureHeader): void
    {
        $webhookSecret = config('services.paymongo.webhook_secret');

        if (! $signatureHeader || ! $this->paymentAdapter->verifyWebhookSignature($rawPayload, $signatureHeader, $webhookSecret)) {
            Log::channel('admin')->warning('PayMongo webhook rejected — invalid or missing signature.');

            return;
        }

        $payload   = json_decode($rawPayload, true);
        $eventType = $payload['data']['attributes']['type'] ?? null;
        $eventData = $payload['data']['attributes']['data'] ?? null;

        Log::channel('admin')->info('PayMongo webhook received.', ['event_type' => $eventType]);

        match ($eventType) {
            'payment.paid'   => $this->handlePaymentPaid($eventData),
            'payment.failed' => $this->handlePaymentFailed($eventData),
            default          => null,
        };
    }

    private function handlePaymentPaid(?array $eventData): void
    {
        if (! $eventData) {
            return;
        }

        $paymentId       = $eventData['id'] ?? null;
        $paymentIntentId = $eventData['attributes']['payment_intent_id'] ?? null;

        if (! $paymentIntentId) {
            Log::channel('admin')->warning('PayMongo payment.paid — no payment_intent_id present.', [
                'payment_id' => $paymentId,
            ]);

            return;
        }

        $payment = $this->paymentRepo->findByGatewayTransactionId($paymentIntentId);

        if (! $payment) {
            Log::channel('admin')->warning('PayMongo payment.paid — no matching payment record found.', [
                'payment_id'        => $paymentId,
                'payment_intent_id' => $paymentIntentId,
            ]);

            return;
        }

        if ($payment->status === 'succeeded') {
            Log::channel('admin')->info('PayMongo payment.paid — already processed, ignoring duplicate.', [
                'payment_intent_id' => $paymentIntentId,
            ]);

            return;
        }

        $this->paymentRepo->markSucceeded($payment);

        $subscription = $payment->payable;

        if (! $subscription instanceof Subscription) {
            Log::channel('admin')->warning('PayMongo payment.paid — payable is not a Subscription.', [
                'payment_intent_id' => $paymentIntentId,
            ]);

            return;
        }

        $subscription = $this->subscriptionRepo->activate($subscription);
        $owner        = $subscription->user;

        Log::channel('admin')->info('Subscription activated via PayMongo payment.paid webhook.', [
            'subscription_uuid' => $subscription->uuid,
            'payment_intent_id' => $paymentIntentId,
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

    private function handlePaymentFailed(?array $eventData): void
    {
        if (! $eventData) {
            return;
        }

        $paymentId       = $eventData['id'] ?? null;
        $paymentIntentId = $eventData['attributes']['payment_intent_id'] ?? null;

        if (! $paymentIntentId) {
            Log::channel('admin')->warning('PayMongo payment.failed — no payment_intent_id present.', [
                'payment_id' => $paymentId,
            ]);

            return;
        }

        $payment = $this->paymentRepo->findByGatewayTransactionId($paymentIntentId);

        if (! $payment) {
            Log::channel('admin')->warning('PayMongo payment.failed — no matching payment record found.', [
                'payment_id'        => $paymentId,
                'payment_intent_id' => $paymentIntentId,
            ]);

            return;
        }

        if ($payment->status === 'failed') {
            Log::channel('admin')->info('PayMongo payment.failed — already processed, ignoring duplicate.', [
                'payment_intent_id' => $paymentIntentId,
            ]);

            return;
        }

        $this->paymentRepo->markFailed($payment);

        $subscription = $payment->payable;

        if (! $subscription instanceof Subscription) {
            return;
        }

        $subscription = $this->subscriptionRepo->markFailed($subscription);
        $owner        = $subscription->user;

        Log::channel('admin')->info('Subscription payment failed via PayMongo webhook.', [
            'subscription_uuid' => $subscription->uuid,
            'payment_intent_id' => $paymentIntentId,
        ]);

        if ($owner) {
            $this->mailer->sendMailable($owner->email, new SubscriptionPaymentMail(
                ownerName: $owner->firstname ?? $owner->username ?? 'there',
                planName: $subscription->plan->sub_name,
                status: 'failed',
            ));

            Log::channel('owner')->info('Subscription payment failure email sent.', [
                'owner_uuid'        => $owner->uuid,
                'subscription_uuid' => $subscription->uuid,
            ]);
        }
    }
}