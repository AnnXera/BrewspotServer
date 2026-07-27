<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreMenuCategoryRequest;
use App\Services\MenuCategoryService;
use Illuminate\Http\JsonResponse;

class MenuCategoryController extends Controller
{
    public function __construct(
        private readonly MenuCategoryService $service
    ) {}

    /**
     * POST /api/owner/menu-categories
     */
    public function store(StoreMenuCategoryRequest $request): JsonResponse
    {
        $result = $this->service->createCategory(
            $request->user(),
            $request->validated('name')
        );

        return response()->json($result, $result['success'] ? 201 : 422);
    }
}