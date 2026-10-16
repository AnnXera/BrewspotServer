<?php

namespace App\Http\Controllers;

use App\Services\OwnerDashboardService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * GET /api/owner/dashboard-stats?range=daily|weekly|monthly
 */
class OwnerDashboardController extends Controller
{
    public function __construct(
        private readonly OwnerDashboardService $service
    ) {}

    public function stats(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'range' => ['nullable', 'in:daily,weekly,monthly'],
        ]);

        return response()->json(
            $this->service->stats($request->user(), $validated['range'] ?? 'daily')
        );
    }
}
