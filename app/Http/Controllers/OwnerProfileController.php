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

    /**
     * GET /api/owner/profile
     */
    public function profile(Request $request): JsonResponse
    {
        $result = $this->service->getProfile($request->user());

        return response()->json($result);
    }

    /**
     * GET /api/owner/cafes
     */
    public function cafes(Request $request): JsonResponse
    {
        $result = $this->service->getCafes($request->user());

        return response()->json($result);
    }

    /**
     * GET /api/owner/branches
     */
    public function branches(Request $request): JsonResponse
    {
        $result = $this->service->getBranches($request->user());

        return response()->json($result);
    }

    /**
     * GET /api/owner/branches/{uuid}
     */
    public function branch(Request $request, string $uuid): JsonResponse
    {
        $result = $this->service->getBranch($request->user(), $uuid);

        return response()->json($result, $result['success'] ? 200 : 404);
    }
}