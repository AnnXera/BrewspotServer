<?php

namespace App\Http\Controllers;

use App\Http\Requests\SaveFloorPlanLayoutRequest;
use App\Http\Requests\StoreFloorPlanRequest;
use App\Http\Requests\UpdateFloorPlanRequest;
use App\Http\Requests\UpdateTableStatusRequest;
use App\Services\FloorPlanService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Floor plans of a branch — shared by owner and manager dashboards:
 *   /api/owner/branches/{branchUuid}/floor-plans/...
 *   /api/manager/branches/{branchUuid}/floor-plans/...
 *
 * `branch.access` has already authorised the branch and stored it on the request.
 */
class FloorPlanController extends Controller
{
    public function __construct(
        private readonly FloorPlanService $service
    ) {}

    /**
     * GET .../floor-plans/assets — valid asset keys, statuses and reservation rules.
     */
    public function assets(): JsonResponse
    {
        return $this->respond($this->service->assets());
    }

    /**
     * GET .../floor-plans
     */
    public function index(Request $request): JsonResponse
    {
        return $this->respond($this->service->list($request->attributes->get('branch')));
    }

    /**
     * POST .../floor-plans
     */
    public function store(StoreFloorPlanRequest $request): JsonResponse
    {
        return $this->respond($this->service->create(
            $request->attributes->get('branch'),
            $request->validated()
        ), 201);
    }

    /**
     * GET .../floor-plans/{planUuid}
     */
    public function show(Request $request, string $branchUuid, string $planUuid): JsonResponse
    {
        return $this->respond($this->service->show($request->attributes->get('branch'), $planUuid));
    }

    /**
     * PATCH .../floor-plans/{planUuid}
     */
    public function update(UpdateFloorPlanRequest $request, string $branchUuid, string $planUuid): JsonResponse
    {
        return $this->respond($this->service->update(
            $request->attributes->get('branch'),
            $planUuid,
            $request->validated()
        ));
    }

    /**
     * DELETE .../floor-plans/{planUuid}
     */
    public function destroy(Request $request, string $branchUuid, string $planUuid): JsonResponse
    {
        return $this->respond($this->service->delete(
            $request->user(),
            $request->attributes->get('branch'),
            $planUuid
        ));
    }

    /**
     * POST .../floor-plans/{planUuid}/activate
     */
    public function activate(Request $request, string $branchUuid, string $planUuid): JsonResponse
    {
        return $this->respond($this->service->activate(
            $request->user(),
            $request->attributes->get('branch'),
            $planUuid
        ));
    }

    /**
     * PUT .../floor-plans/{planUuid}/layout — the editor's Save button.
     */
    public function saveLayout(SaveFloorPlanLayoutRequest $request, string $branchUuid, string $planUuid): JsonResponse
    {
        return $this->respond($this->service->saveLayout(
            $request->attributes->get('branch'),
            $planUuid,
            $request->validated('tables'),
            $request->validated('elements')
        ));
    }

    /**
     * PATCH .../tables/{tableUuid}/status
     */
    public function tableStatus(UpdateTableStatusRequest $request, string $branchUuid, string $tableUuid): JsonResponse
    {
        return $this->respond($this->service->updateTableStatus(
            $request->attributes->get('branch'),
            $tableUuid,
            $request->validated('status')
        ));
    }
}
