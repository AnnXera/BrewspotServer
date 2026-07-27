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
     * POST /api/webhooks/paymongo
     */
    public function handle(Request $request): JsonResponse
    {
        $rawPayload      = $request->getContent();
        $signatureHeader = $request->header('Paymongo-Signature');
        $webhookSecret   = config('services.paymongo.webhook_secret');

        if (! $signatureHeader || ! $this->paymentAdapter->verifyWebhookSignature($rawPayload, $signatureHeader, $webhookSecret)) {
            Log::channel('admin')->warning('PayMongo webhook rejected — invalid or missing signature.');

            return response()->json(['statusCode' => 200, 'body' => ['message' => 'SUCCESS']], 200);
        }

        $payload   = json_decode($rawPayload, true);
        $eventType = $payload['data']['attributes']['type'] ?? null;
        $eventData = $payload['data']['attributes']['data'] ?? null;

        if (str_starts_with((string) $eventType, 'subscription.')) {
            $this->nativeSubscriptionService->handleWebhookEvent($eventType, $eventData ?? []);
        } else {
            $this->checkoutService->handleWebhook($rawPayload, $signatureHeader);
        }

        return response()->json(['statusCode' => 200, 'body' => ['message' => 'SUCCESS']], 200);
    }
}