<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreSubscriptionPlanRequest;
use App\Http\Requests\UpdateSubscriptionPlanRequest;
use App\Services\SubscriptionPlanService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SubscriptionPlanController extends Controller
{
    public function __construct(
        private readonly SubscriptionPlanService $service
    ) {}

    public function index(Request $request): JsonResponse
    {
        $plans = $this->service->listPlans($request->input('per_page', 15));

        return response()->json([
            'success' => true,
            'plans'   => $plans,
        ]);
    }

    public function show(string $uuid): JsonResponse
    {
        $result = $this->service->getPlan($uuid);

        return response()->json($result, $result['success'] ? 200 : 404);
    }

    public function store(StoreSubscriptionPlanRequest $request): JsonResponse
    {
        $result = $this->service->createPlan($request->validated());

        return response()->json($result, $result['success'] ? 201 : 422);
    }

    public function update(UpdateSubscriptionPlanRequest $request, string $uuid): JsonResponse
    {
        $result = $this->service->updatePlan($uuid, $request->validated());

        return response()->json($result, $result['success'] ? 200 : 422);
    }

    public function destroy(string $uuid): JsonResponse
    {
        $result = $this->service->deletePlan($uuid);

        return response()->json($result, $result['success'] ? 200 : 404);
    }

    public function restore(string $uuid): JsonResponse
    {
        $result = $this->service->restorePlan($uuid);

        return response()->json($result, $result['success'] ? 200 : 404);
    }

    public function ownerIndex(Request $request): JsonResponse
    {
        $plans = $this->service->listActivePlansForOwner($request->user(), $request->input('per_page', 15));

        return response()->json([
            'success' => true,
            'plans'   => $plans,
        ]);
    }

    public function ownerShow(Request $request, string $uuid): JsonResponse
    {
        $result = $this->service->getActivePlanForOwner($request->user(), $uuid);

        return response()->json($result, $result['success'] ? 200 : 404);
    }
}