<?php

namespace App\Http\Controllers;

use App\Services\SubscriptionCheckoutService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PaymentWebhookController extends Controller
{
    public function __construct(
        private readonly SubscriptionCheckoutService $service
    ) {}

    /**
     * POST /api/webhooks/paymongo
     * Public endpoint — PayMongo calls this directly, no Sanctum auth.
     */
    public function handle(Request $request): JsonResponse
    {
        $this->service->handleWebhook(
            $request->getContent(),
            $request->header('Paymongo-Signature')
        );

        // Always acknowledge with 2xx so PayMongo doesn't retry — real
        // failures are logged internally instead.
        return response()->json(['statusCode' => 200, 'body' => ['message' => 'SUCCESS']], 200);
    }
}