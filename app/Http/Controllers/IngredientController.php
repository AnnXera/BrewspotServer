<?php

namespace App\Http\Controllers;

use App\Http\Requests\IngredientRequest;
use App\Services\IngredientService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class IngredientController extends Controller
{
    public function __construct(
        private readonly IngredientService $service
    ) {}

    /**
     * GET /api/owner/ingredients?search=&include_retired=1
     * Active ingredients for the recipe picker; the Ingredients page also
     * asks for retired ones.
     */
    public function index(Request $request): JsonResponse
    {
        $search = $request->query('search');
        $result = $this->service->listIngredients(
            $request->user(),
            is_string($search) ? $search : null,
            $request->boolean('include_retired')
        );

        return response()->json($result, $result['success'] ? 200 : 404);
    }

    /**
     * POST /api/owner/ingredients
     */
    public function store(IngredientRequest $request): JsonResponse
    {
        $result = $this->service->createIngredient($request->user(), $request->validated());

        return response()->json($result, $result['success'] ? 201 : ($result['http'] ?? 400));
    }

    /**
     * PATCH /api/owner/ingredients/{uuid} — rename, change unit, retire/restore.
     */
    public function update(IngredientRequest $request, string $uuid): JsonResponse
    {
        $result = $this->service->updateIngredient($request->user(), $uuid, $request->validated());

        return response()->json($result, $result['success'] ? 200 : ($result['http'] ?? 400));
    }
}
