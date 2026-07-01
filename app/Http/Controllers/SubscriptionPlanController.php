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

    /**
     * GET /api/admin/subscription-plans
     * Admin — list all plans (active and inactive).
     */
    public function index(Request $request): JsonResponse
    {
        $plans = $this->service->listPlans($request->input('per_page', 15));

        return response()->json([
            'success' => true,
            'plans'   => $plans,
        ]);
    }

    /**
     * GET /api/admin/subscription-plans/{uuid}
     * Admin — view any plan regardless of active status.
     */
    public function show(string $uuid): JsonResponse
    {
        $result = $this->service->getPlan($uuid);

        return response()->json($result, $result['success'] ? 200 : 404);
    }

    /**
     * POST /api/admin/subscription-plans
     */
    public function store(StoreSubscriptionPlanRequest $request): JsonResponse
    {
        $result = $this->service->createPlan($request->validated());

        return response()->json($result, $result['success'] ? 201 : 422);
    }

    /**
     * PATCH /api/admin/subscription-plans/{uuid}
     */
    public function update(UpdateSubscriptionPlanRequest $request, string $uuid): JsonResponse
    {
        $result = $this->service->updatePlan($uuid, $request->validated());

        return response()->json($result, $result['success'] ? 200 : 422);
    }

    /**
     * DELETE /api/admin/subscription-plans/{uuid}
     */
    public function destroy(string $uuid): JsonResponse
    {
        $result = $this->service->deletePlan($uuid);

        return response()->json($result, $result['success'] ? 200 : 404);
    }

    /**
     * PATCH /api/admin/subscription-plans/{uuid}/restore
     */
    public function restore(string $uuid): JsonResponse
    {
        $result = $this->service->restorePlan($uuid);

        return response()->json($result, $result['success'] ? 200 : 404);
    }

    /**
     * GET /api/owner/subscription-plans
     * Owner — list active plans only.
     */
    public function ownerIndex(Request $request): JsonResponse
    {
        $plans = $this->service->listActivePlans($request->input('per_page', 15));

        return response()->json([
            'success' => true,
            'plans'   => $plans,
        ]);
    }

    /**
     * GET /api/owner/subscription-plans/{uuid}
     * Owner — view a specific active plan only.
     */
    public function ownerShow(string $uuid): JsonResponse
    {
        $result = $this->service->getActivePlan($uuid);

        return response()->json($result, $result['success'] ? 200 : 404);
    }
}