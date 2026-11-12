<?php

namespace App\Http\Controllers;

use App\Http\Requests\OwnerActivityLogRequest;
use App\Services\ActivityLogService;
use Illuminate\Http\JsonResponse;

/**
 * One branch's activity log, for the manager dashboard (and owners, through the same branch routes):
 *   GET /api/manager/branches/{branchUuid}/activity-logs
 *
 * `branch.access` has already authorised the branch; the `branch` query filter is ignored here.
 */
class BranchActivityLogController extends Controller
{
    public function __construct(
        private readonly ActivityLogService $service
    ) {}

    public function index(OwnerActivityLogRequest $request): JsonResponse
    {
        return $this->respond($this->service->listForBranch(
            $request->attributes->get('branch'),
            $request->safe()->except('branch')
        ));
    }
}
