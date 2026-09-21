<?php

namespace App\Http\Controllers;

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
        private readonly PaymentRepository $paymentRepo
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
        
        $this->paymentRepo->markSucceeded($payment, 'paymongo', 'PayMongo');
        
        $subscription = $payment->payable;
        if ($subscription instanceof Subscription) {
            $this->subscriptionRepo->activate($subscription);
            $this->subscriptionRepo->cancelOtherActiveSubscriptions($subscription->user_id, $subscription->sub_id);
        }
    }
    
    private function handleCheckoutFailed(array $resource): void
    {
        $sessionId = $resource['id'] ?? null;
        if (!$sessionId) return;
        
        $payment = $this->paymentRepo->findByGatewayTransactionId($sessionId);
        if (!$payment || $payment->status === 'failed') return;
        
        $this->paymentRepo->markFailed($payment, 'paymongo');
        
        $subscription = $payment->payable;
        if ($subscription instanceof Subscription) {
            $this->subscriptionRepo->markFailed($subscription);
        }
    }

    private function handleSubscriptionActivated(array $resource): void
    {
        $subscriptionId = $resource['id'] ?? null;
        if (!$subscriptionId) return;

        $subscription = $this->subscriptionRepo->findByPayMongoSubscriptionId($subscriptionId) 
            ?? $this->subscriptionRepo->findByGatewayTransactionId($subscriptionId); // Fallback depending on schema

        if (!$subscription) return;

        $nextBilling = isset($resource['attributes']['current_period_end'])
            ? Carbon::createFromTimestamp($resource['attributes']['current_period_end'])
            : null;

        $this->subscriptionRepo->activateFromPayPal($subscription, $nextBilling); // Reusing PayPal method for simplicity as it just sets active and end date
    }

    private function handleSubscriptionPastDue(array $resource): void
    {
        // Similar implementation for past due
    }
    
    private function handleSubscriptionUnpaid(array $resource): void
    {
        // Similar implementation for unpaid / cancelled
    }
}
