<?php

namespace App\Http\Controllers;

use App\Http\Requests\OwnerActivityLogRequest;
use App\Services\ActivityLogService;
use Illuminate\Http\JsonResponse;

/**
 * Owner Activity Log page:
 *   GET /api/owner/activity-logs
 */
class OwnerActivityLogController extends Controller
{
    public function __construct(
        private readonly ActivityLogService $service
    ) {}

    public function index(OwnerActivityLogRequest $request): JsonResponse
    {
        return $this->respond($this->service->listForOwner($request->user(), $request->validated()));
    }
}
