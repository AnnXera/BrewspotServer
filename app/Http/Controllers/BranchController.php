<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreBranchRequest;
use App\Http\Requests\UpdateBranchRequest;
use App\Services\BranchService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class BranchController extends Controller
{
    public function __construct(
        private readonly BranchService $service
    ) {}

    /**
     * POST /api/owner/branches
     */
    public function store(StoreBranchRequest $request): JsonResponse
    {
        $result = $this->service->createBranch($request->user(), $request->validated());

        return response()->json($result, $result['success'] ? 201 : 422);
    }

    /**
     * PATCH /api/owner/branches/{uuid}
     */
    public function update(UpdateBranchRequest $request, string $uuid): JsonResponse
    {
        $result = $this->service->updateBranch($request->user(), $uuid, $request->validated());

        return response()->json($result, $result['success'] ? 200 : 422);
    }

    /**
     * DELETE /api/owner/branches/{uuid}
     */
    public function destroy(Request $request, string $uuid): JsonResponse
    {
        $result = $this->service->deleteBranch($request->user(), $uuid);

        return response()->json($result, $result['success'] ? 200 : 422);
    }
}