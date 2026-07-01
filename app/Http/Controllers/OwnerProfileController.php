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

    public function cafes(Request $request): JsonResponse
    {
        $result = $this->service->getCafes($request->user());

        return response()->json($result);
    }

    public function branches(Request $request): JsonResponse
    {
        $result = $this->service->getBranches($request->user());

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