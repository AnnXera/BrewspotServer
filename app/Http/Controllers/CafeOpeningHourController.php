<?php

namespace App\Http\Controllers;

use App\Http\Requests\UpdateCafeOpeningHoursRequest;
use App\Services\CafeOpeningHourService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CafeOpeningHourController extends Controller
{
    public function __construct(
        private readonly CafeOpeningHourService $service
    ) {}

    public function index(Request $request): JsonResponse
    {
        return $this->respond($this->service->forOwner($request->user()));
    }

    /**
     * GET .../branches/{branchUuid}/opening-hours
     * The branch's cafe hours, read-only, so managers can offer reservation slots inside them.
     * `branch.access` has already authorised the branch and stored it on the request.
     */
    public function branch(Request $request): JsonResponse
    {
        return $this->respond($this->service->forBranch($request->attributes->get('branch')));
    }

    public function update(UpdateCafeOpeningHoursRequest $request): JsonResponse
    {
        return $this->respond($this->service->update($request->user(), $request->validated('hours')));
    }
}
