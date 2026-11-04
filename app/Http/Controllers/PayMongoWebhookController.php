<?php

namespace App\Http\Controllers;

use App\Services\PayMongoWebhookService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PayMongoWebhookController extends Controller
{
    public function __construct(
        private readonly PayMongoWebhookService $service
    ) {}

    /**
     * POST /api/webhooks/paymongo
     */
    public function handle(Request $request): JsonResponse
    {
        $handled = $this->service->handle(
            $request->getContent(),
            $request->header('Paymongo-Signature', '')
        );

        return $handled
            ? response()->json(['message' => 'SUCCESS'])
            : response()->json(['message' => 'Invalid signature'], 400);
    }
}
