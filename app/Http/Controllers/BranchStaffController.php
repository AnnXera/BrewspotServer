<?php

namespace App\Http\Controllers;

use App\Http\Requests\CreateBranchStaffRequest;
use App\Http\Requests\SetStaffPinRequest;
use App\Http\Requests\UpdateBranchStaffRequest;
use App\Http\Requests\UpdateStaffScheduleRequest;
use App\Services\BranchStaffService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Employees tab of a branch — shared by owner and manager dashboards:
 *   /api/owner/branches/{branchUuid}/staff/...
 *   /api/manager/branches/{branchUuid}/staff/...
 *
 * `branch.access` has already authorised the branch and stored it on the request.
 */
class BranchStaffController extends Controller
{
    public function __construct(
        private readonly BranchStaffService $service
    ) {}

    /**
     * GET .../branches/{branchUuid}/staff?include_terminated=1&per_page=15
     */
    public function index(Request $request): JsonResponse
    {
        return $this->respond($this->service->list(
            $request->user(),
            $request->attributes->get('branch'),
            $request->boolean('include_terminated'),
            (int) $request->input('per_page', 15)
        ));
    }

    /**
     * POST .../branches/{branchUuid}/staff
     */
    public function store(CreateBranchStaffRequest $request): JsonResponse
    {
        return $this->respond($this->service->create(
            $request->user(),
            $request->attributes->get('branch'),
            $request->validated()
        ), 201);
    }

    /**
     * GET .../branches/{branchUuid}/staff/{userUuid}
     */
    public function show(Request $request, string $branchUuid, string $userUuid): JsonResponse
    {
        return $this->respond($this->service->show(
            $request->user(),
            $request->attributes->get('branch'),
            $userUuid
        ));
    }

    /**
     * PATCH .../branches/{branchUuid}/staff/{userUuid}
     */
    public function update(UpdateBranchStaffRequest $request, string $branchUuid, string $userUuid): JsonResponse
    {
        return $this->respond($this->service->update(
            $request->user(),
            $request->attributes->get('branch'),
            $userUuid,
            $request->validated()
        ));
    }

    /**
     * PUT .../branches/{branchUuid}/staff/{userUuid}/schedule
     */
    public function updateSchedule(UpdateStaffScheduleRequest $request, string $branchUuid, string $userUuid): JsonResponse
    {
        return $this->respond($this->service->updateSchedule(
            $request->user(),
            $request->attributes->get('branch'),
            $userUuid,
            $request->validated('schedule')
        ));
    }

    /**
     * POST .../branches/{branchUuid}/staff/{userUuid}/terminate
     */
    public function terminate(Request $request, string $branchUuid, string $userUuid): JsonResponse
    {
        return $this->respond($this->service->terminate(
            $request->user(),
            $request->attributes->get('branch'),
            $userUuid
        ));
    }

    /**
     * PUT .../branches/{branchUuid}/staff/{userUuid}/pin
     */
    public function setPin(SetStaffPinRequest $request, string $branchUuid, string $userUuid): JsonResponse
    {
        return $this->respond($this->service->setPin(
            $request->user(),
            $request->attributes->get('branch'),
            $userUuid,
            $request->validated('pin')
        ));
    }
}
