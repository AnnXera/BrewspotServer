<?php

namespace App\Http\Controllers;

use App\Http\Requests\SetCategoryBranchAvailabilityRequest;
use App\Services\CategoryBranchService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CategoryBranchController extends Controller
{
    public function __construct(
        private readonly CategoryBranchService $service
    ) {}

    /**
     * GET /api/owner/menu-categories/{uuid}/branches
     * Only branches with an explicit override.
     */
    public function index(Request $request, string $uuid): JsonResponse
    {
        $result = $this->service->listBranchOverrides($request->user(), $uuid);

        return response()->json($result, $result['success'] ? 200 : 404);
    }

    /**
     * GET /api/owner/menu-categories/{uuid}/branches-status
     * Every branch, with effective (inherited or overridden) availability.
     */
    public function status(Request $request, string $uuid): JsonResponse
    {
        $result = $this->service->listAllBranchesStatus($request->user(), $uuid);

        return response()->json($result, $result['success'] ? 200 : 404);
    }

    /**
     * PATCH /api/owner/menu-categories/{categoryUuid}/branches/{branchUuid}
     */
    public function update(SetCategoryBranchAvailabilityRequest $request, string $categoryUuid, string $branchUuid): JsonResponse
    {
        $result = $this->service->setBranchAvailability(
            $request->user(),
            $categoryUuid,
            $branchUuid,
            $request->validated('is_available')
        );

        return response()->json($result, $result['success'] ? 200 : 422);
    }
}