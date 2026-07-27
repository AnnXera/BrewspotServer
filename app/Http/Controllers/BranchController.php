<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreBranchRequest;
use App\Services\BranchService;
use Illuminate\Http\JsonResponse;

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
}