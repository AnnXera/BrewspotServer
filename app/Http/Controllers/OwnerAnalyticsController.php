<?php

namespace App\Http\Controllers;

use App\Http\Requests\OwnerAnalyticsRequest;
use App\Http\Requests\OwnerTransactionLogRequest;
use App\Services\OwnerAnalyticsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Owner Analytics page, one call per tab:
 *   GET /api/owner/analytics/sales
 *   GET /api/owner/analytics/transactions          (transaction log on the Sales tab)
 *   GET /api/owner/analytics/transactions/{uuid}
 *   GET /api/owner/analytics/menu
 *   GET /api/owner/analytics/branches
 *   GET /api/owner/analytics/operations
 */
class OwnerAnalyticsController extends Controller
{
    public function __construct(
        private readonly OwnerAnalyticsService $service
    ) {}

    public function sales(OwnerAnalyticsRequest $request): JsonResponse
    {
        return $this->respond($this->service->sales($request->user(), $request->validated()));
    }

    public function transactions(OwnerTransactionLogRequest $request): JsonResponse
    {
        return $this->respond($this->service->transactions($request->user(), $request->validated()));
    }

    public function transaction(Request $request, string $uuid): JsonResponse
    {
        return $this->respond($this->service->transaction($request->user(), $uuid));
    }

    public function menu(OwnerAnalyticsRequest $request): JsonResponse
    {
        return $this->respond($this->service->menu($request->user(), $request->validated()));
    }

    public function branches(OwnerAnalyticsRequest $request): JsonResponse
    {
        return $this->respond($this->service->branches($request->user(), $request->validated()));
    }

    public function operations(OwnerAnalyticsRequest $request): JsonResponse
    {
        return $this->respond($this->service->operations($request->user(), $request->validated()));
    }
}
