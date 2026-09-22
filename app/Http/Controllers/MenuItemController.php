<?php

namespace App\Http\Controllers;

use App\Http\Requests\MenuItemRequest;
use App\Services\MenuItemService;
use Illuminate\Http\JsonResponse;

class MenuItemController extends Controller
{
    public function __construct(
        private readonly MenuItemService $service
    ) {}

    public function index(\Illuminate\Http\Request $request): JsonResponse
    {
        $result = $this->service->listItems(auth()->user(), $request->query('category_uuid'));

        if (! $result['success']) {
            return response()->json($result, 400);
        }

        return response()->json($result, 200);
    }

    public function store(MenuItemRequest $request): JsonResponse
    {
        $result = $this->service->createItem(auth()->user(), $request->validated());

        if (! $result['success']) {
            return response()->json($result, 400);
        }

        return response()->json($result, 201);
    }

    public function update(MenuItemRequest $request, string $uuid): JsonResponse
    {
        $result = $this->service->updateItem(auth()->user(), $uuid, $request->validated());

        if (! $result['success']) {
            return response()->json($result, 400);
        }

        return response()->json($result, 200);
    }

    public function destroy(string $uuid): JsonResponse
    {
        $result = $this->service->deleteItem(auth()->user(), $uuid);

        if (! $result['success']) {
            return response()->json($result, 400);
        }

        return response()->json($result, 200);
    }
}
