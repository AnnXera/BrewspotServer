<?php

namespace App\Http\Controllers;

use App\Http\Requests\MenuCategoryRequest;
use App\Services\MenuCategoryService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class MenuCategoryController extends Controller
{
    public function __construct(
        private readonly MenuCategoryService $service
    ) {}

    /**
     * GET /api/owner/menu-categories
     */
    public function index(Request $request): JsonResponse
    {
        $result = $this->service->listCategories($request->user());

        return response()->json($result, $result['success'] ? 200 : 404);
    }

    /**
     * POST /api/owner/menu-categories
     */
    public function store(MenuCategoryRequest $request): JsonResponse
    {
        $result = $this->service->createCategory($request->user(), $request->validated());

        return response()->json($result, $result['success'] ? 201 : 422);
    }

    /**
     * PATCH /api/owner/menu-categories/{uuid}
     */
    public function update(MenuCategoryRequest $request, string $uuid): JsonResponse
    {
        $result = $this->service->updateCategory($request->user(), $uuid, $request->validated());

        return response()->json($result, $result['success'] ? 200 : 422);
    }
}