<?php

namespace App\Http\Controllers;

use App\Contracts\PaymentAdapterInterface;
use App\Services\NativeSubscriptionService;
use App\Services\SubscriptionCheckoutService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class PaymentWebhookController extends Controller
{
    public function __construct(
        private readonly SubscriptionCheckoutService $checkoutService,
        private readonly NativeSubscriptionService $nativeSubscriptionService,
        private readonly PaymentAdapterInterface $paymentAdapter
    ) {}

    /**
     * POST /api/webhooks/paypal
     */
    public function handle(Request $request): JsonResponse
    {
        $rawPayload = $request->getContent();

        $headerBundle = json_encode([
            'paypal-transmission-id'   => $request->header('Paypal-Transmission-Id'),
            'paypal-transmission-time' => $request->header('Paypal-Transmission-Time'),
            'paypal-cert-url'          => $request->header('Paypal-Cert-Url'),
            'paypal-auth-algo'         => $request->header('Paypal-Auth-Algo'),
            'paypal-transmission-sig'  => $request->header('Paypal-Transmission-Sig'),
        ]);

        $webhookId = config('services.paypal.webhook_id');

        if (! $this->paymentAdapter->verifyWebhookSignature($rawPayload, $headerBundle, $webhookId)) {
            Log::channel('admin')->warning('PayPal webhook rejected — invalid or unverifiable signature.');

            return response()->json(['statusCode' => 200, 'body' => ['message' => 'SUCCESS']], 200);
        }

        $payload   = json_decode($rawPayload, true);
        $eventType = $payload['event_type'] ?? null;
        $resource  = $payload['resource'] ?? [];

        Log::channel('admin')->info('PayPal webhook received.', ['event_type' => $eventType]);

        match (true) {
            str_starts_with((string) $eventType, 'BILLING.SUBSCRIPTION.') => $this->nativeSubscriptionService->handleWebhookEvent($eventType, $resource),
            in_array($eventType, ['CHECKOUT.ORDER.APPROVED', 'PAYMENT.CAPTURE.DENIED'], true) => $this->checkoutService->handleWebhook($eventType, $resource),
            default => Log::channel('admin')->info('PayPal webhook — unhandled event type.', ['event_type' => $eventType]),
        };

        return response()->json(['statusCode' => 200, 'body' => ['message' => 'SUCCESS']], 200);
    }
}