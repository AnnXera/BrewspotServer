<?php

namespace App\Http\Controllers;

use App\Http\Requests\CreateSubscriptionCheckoutRequest;
use App\Http\Requests\SchedulePlanChangeRequest;
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
        $result = $this->service->createCheckout(
            $request->user(),
            $request->validated('plan_uuid'),
            $request->validated('billing_cycle'),
            $request->validated('gateway') ?? 'paymongo'
        );

        return response()->json($result, $result['success'] ? 201 : 422);
    }

    /**
     * POST /api/owner/subscriptions/schedule-change
     *
     * Books a plan change for the end of the owner's current term. Responds with
     * `requires_checkout` when the change should happen immediately instead — currently
     * when the owner has no running subscription, or is still on a free trial.
     */
    public function schedule(SchedulePlanChangeRequest $request): JsonResponse
    {
        $result = $this->service->schedulePlanChange(
            $request->user(),
            $request->validated('plan_uuid'),
            $request->validated('billing_cycle')
        );

        return response()->json($result, $result['success'] ? 200 : 422);
    }
}