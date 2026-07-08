<?php

namespace App\Services;

use App\Contracts\PaymentAdapterInterface;
use App\Models\User;
use App\Repository\PaymentRepository;
use App\Repository\SubscriptionPlanRepository;
use App\Repository\SubscriptionRepository;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

class NativeSubscriptionService
{
    public function __construct(
        private readonly SubscriptionPlanRepository $planRepo,
        private readonly SubscriptionRepository $subscriptionRepo,
        private readonly PaymentRepository $paymentRepo,
        private readonly PaymentAdapterInterface $paymentAdapter
    ) {}

    public function createSubscription(User $owner, string $planUuid, array $cardInput): array
    {
        $plan = $this->planRepo->findByUuid($planUuid);

        if (! $plan) {
            return ['success' => false, 'message' => 'Subscription plan not found.'];
        }

        try {
            if (! $owner->paymongo_customer_id) {
                $customer = $this->paymentAdapter->createCustomer([
                    'first_name' => $owner->firstname,
                    'last_name'  => $owner->lastname,
                    'email'      => $owner->email,
                    'phone'      => $this->formatPhoneNumber($owner->phone_number),
                    'default_device' => 'phone',
                ]);

                $owner->update(['paymongo_customer_id' => $customer['id']]);
            }

            if (! $plan->paymongo_plan_id) {
                $paymongoPlan = $this->paymentAdapter->createPlan([
                    'name'           => $plan->sub_name,
                    'description'    => $plan->description ?? $plan->sub_name,
                    'amount'         => (string) ((int) round($plan->price * 100)),
                    'currency'       => 'PHP',
                    'interval'       => 'monthly',
                    'interval_count' => '1',
                ]);

                $plan = $this->planRepo->syncPayMongoPlanId($plan, $paymongoPlan['id']);
            }

            $paymongoSubscription = $this->paymentAdapter->createSubscription(
                $owner->paymongo_customer_id,
                $plan->paymongo_plan_id
            );

            $paymentIntentId = $paymongoSubscription['attributes']['payment_intent_id']
                ?? $paymongoSubscription['attributes']['payment_intent']['id']
                ?? null;

            if (! $paymentIntentId) {
                Log::channel('owner')->error('Native subscription created but no payment_intent_id found in response.', [
                    'raw_response' => $paymongoSubscription,
                ]);

                return [
                    'success' => false,
                    'message' => config('app.debug')
                        ? 'No payment_intent_id in PayMongo subscription response: ' . json_encode($paymongoSubscription)
                        : 'Subscription created but payment setup failed. Contact support.',
                ];
            }

            $subscription = $this->subscriptionRepo->createPendingWithPayMongo(
                $owner->user_id,
                $plan,
                $paymongoSubscription['id']
            );

            $paymentMethod = $this->paymentAdapter->createCardPaymentMethod(
                [
                    'card_number' => $cardInput['card_number'],
                    'exp_month'   => $cardInput['exp_month'],
                    'exp_year'    => $cardInput['exp_year'],
                    'cvc'         => $cardInput['cvc'],
                ],
                [
                    'name'    => $cardInput['billing_name'],
                    'email'   => $cardInput['billing_email'],
                    'phone'   => $this->formatPhoneNumber($cardInput['billing_phone']),
                    'address' => [
                        'line1'       => $cardInput['address_line1'] ?? null,
                        'line2'       => $cardInput['address_line2'] ?? null,
                        'city'        => $cardInput['city'] ?? null,
                        'state'       => $cardInput['state'] ?? null,
                        'postal_code' => $cardInput['postal_code'] ?? null,
                        'country'     => $cardInput['country'] ?? 'PH',
                    ],
                ]
            );

            $attachResult = $this->paymentAdapter->attachPaymentMethodToIntent($paymentIntentId, $paymentMethod['id']);

            Log::channel('owner')->info('Native subscription initiated.', [
                'owner_uuid'               => $owner->uuid,
                'subscription_uuid'        => $subscription->uuid,
                'paymongo_subscription_id' => $paymongoSubscription['id'],
                'attach_status'            => $attachResult['attributes']['status'] ?? null,
            ]);

            return [
                'success'           => true,
                'message'           => 'Subscription created. Pay the first invoice within 24 hours or it will be cancelled.',
                'subscription_uuid' => $subscription->uuid,
                'payment_status'    => $attachResult['attributes']['status'] ?? null,
                'next_action'       => $attachResult['attributes']['next_action'] ?? null,
            ];
        } catch (\Throwable $e) {
            Log::channel('owner')->error('Native subscription creation failed.', [
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

    private function formatPhoneNumber(?string $phone): ?string
    {
        if (! $phone) {
            return null;
        }

        $digits = preg_replace('/[^\d]/', '', $phone);

        if (str_starts_with($digits, '63')) {
            $digits = substr($digits, 2);
        } elseif (str_starts_with($digits, '0')) {
            $digits = substr($digits, 1);
        }

        return '+63' . $digits;
    }

    public function handleWebhookEvent(string $eventType, array $eventData): void
    {
        $paymongoSubscriptionId = $this->extractSubscriptionId($eventType, $eventData);

        if (! $paymongoSubscriptionId) {
            Log::channel('admin')->warning('Native subscription webhook — could not determine subscription id.', [
                'event_type' => $eventType,
            ]);

            return;
        }

        $subscription = $this->subscriptionRepo->findByPayMongoSubscriptionId($paymongoSubscriptionId);

        if (! $subscription) {
            Log::channel('admin')->warning('Native subscription webhook — no matching local subscription.', [
                'event_type'               => $eventType,
                'paymongo_subscription_id' => $paymongoSubscriptionId,
            ]);

            return;
        }

        match ($eventType) {
            'subscription.activated'                      => $this->handleActivated($subscription, $eventData),
            'subscription.invoice.paid'                    => $this->handleInvoicePaid($subscription, $eventData),
            'subscription.past_due'                        => $this->subscriptionRepo->markPastDue($subscription),
            'subscription.unpaid', 'subscription.updated'  => $this->subscriptionRepo->markCancelledByPayMongo($subscription),
            default                                         => Log::channel('admin')->info('Native subscription webhook — unhandled event type.', ['event_type' => $eventType]),
        };
    }

    private function extractSubscriptionId(string $eventType, array $eventData): ?string
    {
        if (str_starts_with($eventType, 'subscription.') && ! str_contains($eventType, 'invoice')) {
            return $eventData['id'] ?? null;
        }

        return $eventData['attributes']['subscription_id'] ?? $eventData['attributes']['subscription']['id'] ?? null;
    }

    private function handleActivated($subscription, array $eventData): void
    {
        $nextBilling = isset($eventData['attributes']['next_billing_schedule'])
            ? Carbon::parse($eventData['attributes']['next_billing_schedule'])
            : null;

        $subscription = $this->subscriptionRepo->activateFromPayMongo($subscription, $nextBilling);

        Log::channel('admin')->info('Native subscription activated.', [
            'subscription_uuid' => $subscription->uuid,
        ]);
    }

    private function handleInvoicePaid($subscription, array $eventData): void
    {
        $amount    = $eventData['attributes']['amount'] ?? null;
        $invoiceId = $eventData['id'] ?? null;

        if ($amount !== null) {
            $this->paymentRepo->create([
                'user_id'                => $subscription->user_id,
                'payable_type'           => \App\Models\Subscription::class,
                'payable_id'             => $subscription->sub_id,
                'amount'                 => $amount,
                'payment_method_type'    => 'card',
                'gateway_transaction_id' => $invoiceId . '_' . now()->timestamp,
                'status'                 => 'succeeded',
            ]);
        }

        $nextBilling = isset($eventData['attributes']['next_billing_schedule'])
            ? Carbon::parse($eventData['attributes']['next_billing_schedule'])
            : null;

        $this->subscriptionRepo->activateFromPayMongo($subscription, $nextBilling);

        Log::channel('admin')->info('Native subscription invoice paid — cycle extended.', [
            'subscription_uuid' => $subscription->uuid,
        ]);
    }
}