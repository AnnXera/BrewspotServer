<?php

namespace App\Http\Controllers;

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
    public function stats(Request $request, string $branchUuid): JsonResponse
    {
        $validated = $request->validate([
            'range' => ['nullable', 'in:daily,weekly,monthly'],
        ]);

        $branch = $request->attributes->get('branch');

        return response()->json(
            $this->service->stats($branch, $validated['range'] ?? 'daily')
        );
    }

    /**
     * GET /api/manager/branches/{branchUuid}/transactions?status=&search=&page=1&per_page=15
     */
    public function transactions(Request $request, string $branchUuid): JsonResponse
    {
        $validated = $request->validate([
            'status'   => ['nullable', 'string', 'in:completed,paid,void,refunded'],
            'search'   => ['nullable', 'string', 'max:100'],
            'from'     => ['nullable', 'date'],
            'to'       => ['nullable', 'date'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $branch = $request->attributes->get('branch');

        return response()->json(
            $this->service->transactions($branch, $validated, (int) ($validated['per_page'] ?? 15))
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
