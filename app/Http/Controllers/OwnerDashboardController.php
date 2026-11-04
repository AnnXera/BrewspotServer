<?php

namespace App\Http\Controllers;

use App\Http\Requests\DashboardRangeRequest;
use App\Services\OwnerDashboardService;
use Illuminate\Http\JsonResponse;

/**
 * GET /api/owner/dashboard-stats?range=daily|weekly|monthly
 */
class OwnerDashboardController extends Controller
{
    public function __construct(
        private readonly OwnerDashboardService $service
    ) {}

    public function stats(DashboardRangeRequest $request): JsonResponse
    {
        return response()->json(
            $this->service->stats($request->user(), $request->range())
        );
    }
}
