<?php

namespace App\Http\Controllers;

use App\Contracts\MailAdapterInterface;
use App\Mail\SubscriptionPaymentMail;
use App\Services\PaymentGatewayManager;
use App\Models\Subscription;
use App\Repository\PaymentRepository;
use App\Repository\SubscriptionRepository;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Carbon;

class PayMongoWebhookController extends Controller
{
    public function __construct(
        private readonly PaymentGatewayManager $paymentManager,
        private readonly SubscriptionRepository $subscriptionRepo,
        private readonly PaymentRepository $paymentRepo,
        private readonly MailAdapterInterface $mailer
    ) {}

    public function handle(Request $request): JsonResponse
    {
        $rawPayload = $request->getContent();
        $signatureHeader = $request->header('Paymongo-Signature', '');
        
        $adapter = $this->paymentManager->gateway('paymongo');

        if (! $adapter->verifyWebhookSignature($rawPayload, $signatureHeader)) {
            Log::channel('admin')->warning('PayMongo webhook rejected — invalid or unverifiable signature.');
            return response()->json(['message' => 'Invalid signature'], 400);
        }

        $payload = json_decode($rawPayload, true);
        $eventType = $payload['data']['attributes']['type'] ?? null;
        $resource = $payload['data']['attributes']['data'] ?? [];

        Log::channel('admin')->info('PayMongo webhook received.', ['event_type' => $eventType]);

        match ($eventType) {
            'checkout_session.payment.paid' => $this->handleCheckoutPaid($resource),
            'checkout_session.payment.failed' => $this->handleCheckoutFailed($resource),
            // Payment-level events carry a pay_* id instead of the cs_* id the payment row
            // is keyed on, so these resolve the subscription from the event metadata.
            'payment.paid'   => $this->handlePaymentEvent($resource, 'paid'),
            'payment.failed' => $this->handlePaymentEvent($resource, 'failed'),
            'subscription.activated' => $this->handleSubscriptionActivated($resource),
            'subscription.past_due' => $this->handleSubscriptionPastDue($resource),
            'subscription.unpaid' => $this->handleSubscriptionUnpaid($resource),
            default => Log::channel('admin')->info('PayMongo webhook — unhandled event type.', ['event_type' => $eventType]),
        };

        return response()->json(['message' => 'SUCCESS']);
    }

    private function handleCheckoutPaid(array $resource): void
    {
        $sessionId = $resource['id'] ?? null;
        if (!$sessionId) return;

        $payment = $this->paymentRepo->findByGatewayTransactionId($sessionId);
        if (!$payment || $payment->status === 'succeeded') return;

        $this->paymentRepo->markSucceeded($payment, 'paymongo', $this->resolvePaymentInstrument($resource));

        $subscription = $payment->payable;
        if (! $subscription instanceof Subscription) {
            Log::channel('admin')->warning('PayMongo checkout paid — payable is not a Subscription.', [
                'checkout_session_id' => $sessionId,
            ]);

            return;
        }

        $subscription = $this->subscriptionRepo->activate($subscription);
        $this->subscriptionRepo->cancelOtherActiveSubscriptions($subscription->user_id, $subscription->sub_id);

        Log::channel('admin')->info('Subscription activated via PayMongo checkout.', [
            'subscription_uuid'   => $subscription->uuid,
            'checkout_session_id' => $sessionId,
        ]);

        $this->sendPaymentEmail($subscription, 'active');
    }

    /**
     * Handles payment.paid / payment.failed.
     *
     * These fire alongside the checkout_session.* events and carry a pay_* id, which does
     * not match the cs_* id stored on the payment row. PayMongo copies the checkout
     * session's metadata onto the payment, so the subscription is resolved from there
     * instead. Both outcomes are idempotent, so it does not matter whether this or the
     * checkout_session.* handler runs first — whichever arrives second is a no-op.
     */
    private function handlePaymentEvent(array $resource, string $outcome): void
    {
        $subscriptionUuid = $resource['attributes']['metadata']['subscription_uuid'] ?? null;

        if (! $subscriptionUuid) {
            Log::channel('admin')->info('PayMongo payment event carried no subscription metadata — ignoring.', [
                'outcome'    => $outcome,
                'payment_id' => $resource['id'] ?? null,
            ]);

            return;
        }

        $subscription = $this->subscriptionRepo->findByUuid($subscriptionUuid);

        if (! $subscription) {
            Log::channel('admin')->warning('PayMongo payment event — no matching subscription.', [
                'outcome'           => $outcome,
                'subscription_uuid' => $subscriptionUuid,
            ]);

            return;
        }

        $payment = $this->paymentRepo->findPendingForSubscription($subscription);

        if (! $payment) {
            // Already settled by the checkout_session.* handler.
            return;
        }

        if ($outcome === 'paid') {
            $this->paymentRepo->markSucceeded($payment, 'paymongo', $this->resolvePaymentInstrument($resource));

            $subscription = $this->subscriptionRepo->activate($subscription);
            $this->subscriptionRepo->cancelOtherActiveSubscriptions($subscription->user_id, $subscription->sub_id);
        } else {
            $this->paymentRepo->markFailed($payment, 'paymongo');
            $subscription = $this->subscriptionRepo->markFailed($subscription);
        }

        Log::channel('admin')->info('Subscription settled via PayMongo payment event.', [
            'subscription_uuid' => $subscription->uuid,
            'outcome'           => $outcome,
            'payment_id'        => $resource['id'] ?? null,
        ]);

        $this->sendPaymentEmail($subscription, $outcome === 'paid' ? 'active' : 'failed');
    }

    /**
     * Label the funding method the owner actually used — GCash, Maya, a card brand — so
     * payment history shows something more useful than the gateway's name.
     *
     * Accepts either payload shape: a checkout_session resource nests its payments, while
     * a payment.* resource carries the source directly.
     */
    private function resolvePaymentInstrument(array $resource): string
    {
        // checkout_session.* nests the payment; payment.* is the payment itself.
        $payment = $resource['attributes']['payments'][0]['attributes']
            ?? $resource['attributes']
            ?? [];

        $source = $payment['source']['type'] ?? ($payment['payment_method_used'] ?? null);

        return match (strtolower((string) $source)) {
            'gcash'    => 'GCash',
            'paymaya'  => 'Maya',
            'grab_pay' => 'GrabPay',
            'card'     => trim(ucfirst((string) ($payment['source']['brand'] ?? 'Card'))),
            default    => 'PayMongo',
        };
    }

    private function sendPaymentEmail(Subscription $subscription, string $status): void
    {
        $owner = $subscription->user;

        if (! $owner) {
            return;
        }

        $plan = $subscription->plan;

        $this->mailer->sendMailable($owner->email, new SubscriptionPaymentMail(
            ownerName: $owner->firstname ?? $owner->username ?? 'there',
            planName: $plan->sub_name,
            status: $status,
            amount: number_format(
                $subscription->billing_cycle === 'yearly'
                    ? ($plan->yearly_price ?? $plan->price)
                    : $plan->price,
                2
            ),
            endDate: $subscription->end_date?->format('F j, Y'),
        ));

        Log::channel('owner')->info('Subscription payment email sent.', [
            'owner_uuid'        => $owner->uuid,
            'subscription_uuid' => $subscription->uuid,
            'status'            => $status,
        ]);
    }
    
    private function handleCheckoutFailed(array $resource): void
    {
        $sessionId = $resource['id'] ?? null;
        if (!$sessionId) return;
        
        $payment = $this->paymentRepo->findByGatewayTransactionId($sessionId);
        if (!$payment || $payment->status === 'failed') return;

        $this->paymentRepo->markFailed($payment, 'paymongo');

        $subscription = $payment->payable;
        if (! $subscription instanceof Subscription) {
            return;
        }

        $subscription = $this->subscriptionRepo->markFailed($subscription);

        Log::channel('admin')->info('Subscription payment failed via PayMongo checkout.', [
            'subscription_uuid'   => $subscription->uuid,
            'checkout_session_id' => $sessionId,
        ]);

        $this->sendPaymentEmail($subscription, 'failed');
    }

    /**
     * The subscription.* events below only fire once PayMongo enables gateway-managed
     * recurring billing on the account. Until then renewals are owner-initiated through
     * the expiration reminder, and only the checkout_session.* events above are used.
     */
    private function handleSubscriptionActivated(array $resource): void
    {
        $subscription = $this->findSubscriptionForEvent($resource, 'subscription.activated');

        if (! $subscription) {
            return;
        }

        $nextBilling = isset($resource['attributes']['current_period_end'])
            ? Carbon::createFromTimestamp($resource['attributes']['current_period_end'])
            : null;

        $this->subscriptionRepo->activateFromGateway($subscription, $nextBilling);

        Log::channel('admin')->info('Subscription activated via PayMongo subscription event.', [
            'subscription_uuid' => $subscription->uuid,
        ]);
    }

    private function handleSubscriptionPastDue(array $resource): void
    {
        $subscription = $this->findSubscriptionForEvent($resource, 'subscription.past_due');

        if (! $subscription) {
            return;
        }

        $this->subscriptionRepo->markPastDue($subscription);

        Log::channel('admin')->warning('Subscription marked past due by PayMongo.', [
            'subscription_uuid' => $subscription->uuid,
        ]);
    }

    private function handleSubscriptionUnpaid(array $resource): void
    {
        $subscription = $this->findSubscriptionForEvent($resource, 'subscription.unpaid');

        if (! $subscription) {
            return;
        }

        $subscription = $this->subscriptionRepo->markCancelledByGateway($subscription);

        Log::channel('admin')->warning('Subscription cancelled — unpaid at PayMongo.', [
            'subscription_uuid' => $subscription->uuid,
        ]);

        $this->sendPaymentEmail($subscription, 'failed');
    }

    private function findSubscriptionForEvent(array $resource, string $eventType): ?Subscription
    {
        $gatewaySubscriptionId = $resource['id'] ?? null;

        if (! $gatewaySubscriptionId) {
            Log::channel('admin')->warning('PayMongo subscription event carried no id.', [
                'event_type' => $eventType,
            ]);

            return null;
        }

        $subscription = $this->subscriptionRepo->findByGatewaySubscriptionId($gatewaySubscriptionId);

        if (! $subscription) {
            Log::channel('admin')->warning('PayMongo subscription event — no matching subscription.', [
                'event_type'              => $eventType,
                'gateway_subscription_id' => $gatewaySubscriptionId,
            ]);
        }

        return $subscription;
    }
}
