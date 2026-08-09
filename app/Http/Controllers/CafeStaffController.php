<?php

namespace App\Http\Controllers;

use App\Http\Requests\CreateStaffRequest;
use App\Services\CafeStaffService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CafeStaffController extends Controller
{
    public function __construct(
        private readonly CafeStaffService $service
    ) {}

    /**
     * POST /api/owner/staff
     */
    public function store(CreateStaffRequest $request): JsonResponse
    {
        $result = $this->service->createStaff($request->user(), $request->validated());

        return response()->json($result, $result['success'] ? 201 : 422);
    }

    /**
     * GET /api/owner/staff
     */
    public function index(Request $request): JsonResponse
    {
        $result = $this->service->listStaff($request->user(), $request->input('per_page', 15));

        return response()->json($result, $result['success'] ? 200 : 404);
    }

    /**
     * DELETE /api/owner/staff/{uuid}
     */
    public function destroy(Request $request, string $uuid): JsonResponse
    {
        $result = $this->service->removeStaff($request->user(), $uuid);

        return response()->json($result, $result['success'] ? 200 : 404);
    }
}