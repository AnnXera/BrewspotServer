<?php

namespace App\Http\Controllers;

use App\Http\Requests\CreateNativeSubscriptionRequest;
use App\Services\NativeSubscriptionService;
use Illuminate\Http\JsonResponse;

class NativeSubscriptionController extends Controller
{
    public function __construct(
        private readonly NativeSubscriptionService $service
    ) {}

    /**
     * POST /api/owner/subscriptions/native
     */
    public function store(CreateNativeSubscriptionRequest $request): JsonResponse
    {
        $result = $this->service->createSubscription(
            $request->user(),
            $request->validated('plan_uuid'),
            $request->validated()
        );

        return response()->json($result, $result['success'] ? 201 : 422);
    }
}