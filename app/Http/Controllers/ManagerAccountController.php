<?php

namespace App\Http\Controllers;

use App\Http\Requests\ChangeOwnPinRequest;
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
     * GET /api/manager/branches — branch switcher.
     */
    public function branches(Request $request): JsonResponse
    {
        return $this->respond($this->staff->managedBranches($request->user()));
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
