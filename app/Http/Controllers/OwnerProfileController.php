<?php

namespace App\Http\Controllers;

use App\Services\OwnerProfileService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class OwnerProfileController extends Controller
{
    public function __construct(
        private readonly OwnerProfileService $service
    ) {}

    public function profile(Request $request): JsonResponse
    {
        $result = $this->service->getProfile($request->user());

        return response()->json($result);
    }

    public function dismissWelcome(Request $request): JsonResponse
    {
        return response()->json($this->service->markWelcomed($request->user()));
    }

    public function cafes(Request $request): JsonResponse
    {
        $result = $this->service->getCafes($request->user());

        return response()->json($result);
    }

    public function lowStockThreshold(Request $request): JsonResponse
    {
        return $this->respond($this->service->getLowStockThreshold($request->user()));
    }

    public function updateLowStockThreshold(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'low_stock_threshold_percent' => ['required', 'integer', 'min:1', 'max:99'],
        ]);

        return $this->respond($this->service->updateLowStockThreshold(
            $request->user(),
            (int) $validated['low_stock_threshold_percent']
        ));
    }

    public function branches(Request $request): JsonResponse
    {
        $result = $this->service->getBranches(
            $request->user(),
            $request->input('per_page', 6),
            $request->input('search'),
            $request->input('status')
        );

        return response()->json($result);
    }

    public function branch(Request $request, string $uuid): JsonResponse
    {
        $result = $this->service->getBranch($request->user(), $uuid);

        return response()->json($result, $result['success'] ? 200 : 404);
    }

    public function currentPlan(Request $request): JsonResponse
    {
        $result = $this->service->getCurrentPlan($request->user());

        return response()->json($result, $result['success'] ? 200 : 404);
    }

    public function planHistory(Request $request): JsonResponse
    {
        $history = $this->service->getPlanHistory($request->user(), $request->input('per_page', 15));

        return response()->json([
            'success' => true,
            'history' => $history,
        ]);
    }
}