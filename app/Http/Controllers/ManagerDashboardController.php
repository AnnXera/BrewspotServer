<?php

namespace App\Http\Controllers;

use App\Http\Requests\DashboardRangeRequest;
use App\Http\Requests\ManagerTransactionsRequest;
use App\Http\Requests\OwnerTransactionLogRequest;
use App\Services\ManagerDashboardService;
use App\Services\OwnerAnalyticsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ManagerDashboardController extends Controller
{
    public function __construct(
        private readonly ManagerDashboardService $service,
        private readonly OwnerAnalyticsService $analytics
    ) {}

    /**
     * GET /api/manager/branches/{branchUuid}/transaction-log?from=&to=&status=&search=&page=&per_page=
     * The owner dashboard's transaction log, for this branch only (a branch param is ignored).
     */
    public function transactionLog(OwnerTransactionLogRequest $request, string $branchUuid): JsonResponse
    {
        return $this->respond(
            $this->analytics->branchTransactions($request->attributes->get('branch'), $request->validated())
        );
    }

    /**
     * GET /api/manager/branches/{branchUuid}/transaction-log/{transactionUuid}
     */
    public function transactionLogReceipt(Request $request, string $branchUuid, string $transactionUuid): JsonResponse
    {
        return $this->respond(
            $this->analytics->branchTransaction($request->attributes->get('branch'), $transactionUuid)
        );
    }

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
