<?php

namespace App\Http\Controllers;

use App\Http\Requests\DashboardRangeRequest;
use App\Http\Requests\ManagerTransactionsRequest;
use App\Services\ManagerDashboardService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ManagerDashboardController extends Controller
{
    public function __construct(
        private readonly ManagerDashboardService $service
    ) {}

    /**
     * GET /api/manager/branches/{branchUuid}/dashboard-stats?range=daily|weekly|monthly
     */
    public function stats(DashboardRangeRequest $request, string $branchUuid): JsonResponse
    {
        return response()->json(
            $this->service->stats($request->attributes->get('branch'), $request->range())
        );
    }

    /**
     * GET /api/manager/branches/{branchUuid}/transactions?status=&search=&page=1&per_page=15
     */
    public function transactions(ManagerTransactionsRequest $request, string $branchUuid): JsonResponse
    {
        return response()->json(
            $this->service->transactions($request->attributes->get('branch'), $request->validated(), $request->perPage())
        );
    }

    /**
     * GET /api/manager/branches/{branchUuid}/transactions/{transactionUuid}
     */
    public function transaction(Request $request, string $branchUuid, string $transactionUuid): JsonResponse
    {
        $branch = $request->attributes->get('branch');

        return $this->respond(
            $this->service->transaction($branch, $transactionUuid)
        );
    }
}
