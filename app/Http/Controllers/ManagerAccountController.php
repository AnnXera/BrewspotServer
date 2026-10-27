<?php

namespace App\Http\Controllers;

use App\Http\Requests\ChangeOwnPinRequest;
use App\Http\Resources\CafeBranchResource;
use App\Services\BranchStaffService;
use App\Services\StaffPinService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ManagerAccountController extends Controller
{
    public function __construct(
        private readonly BranchStaffService $staff,
        private readonly StaffPinService $pins
    ) {}

    /**
     * GET /api/manager/branches — the manager's assigned branch (a manager runs exactly one).
     */
    public function branches(Request $request): JsonResponse
    {
        return $this->respond($this->staff->managedBranches($request->user()));
    }

    /**
     * GET /api/manager/branches/{branchUuid} — details of an assigned branch.
     * `branch.access` has already authorised the branch and stored it on the request.
     */
    public function branch(Request $request): JsonResponse
    {
        $branch = $request->attributes->get('branch')->load(['cafe', 'documents']);

        return $this->respond([
            'success' => true,
            'branch'  => new CafeBranchResource($branch),
        ]);
    }

    /**
     * PUT /api/manager/pin — manager sets their own PIN.
     */
    public function updatePin(ChangeOwnPinRequest $request): JsonResponse
    {
        return $this->respond($this->pins->changeOwnPin(
            $request->user(),
            $request->validated('current_password'),
            $request->validated('pin')
        ));
    }
}
