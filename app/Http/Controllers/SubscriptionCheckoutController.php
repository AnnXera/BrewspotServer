<?php

namespace App\Http\Controllers;

use App\Http\Requests\CreateSubscriptionCheckoutRequest;
use App\Services\SubscriptionCheckoutService;
use Illuminate\Http\JsonResponse;

class SubscriptionCheckoutController extends Controller
{
    public function __construct(
        private readonly SubscriptionCheckoutService $service
    ) {}

    /**
     * POST /api/owner/subscriptions/checkout
     */
    public function store(CreateSubscriptionCheckoutRequest $request): JsonResponse
    {
        $result = $this->service->createCheckout($request->user(), $request->validated('plan_uuid'));

        return response()->json($result, $result['success'] ? 201 : 422);
    }
}