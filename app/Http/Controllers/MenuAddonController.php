<?php

namespace App\Http\Controllers;

use App\Http\Requests\MenuAddonRequest;
use App\Services\MenuAddonService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class MenuAddonController extends Controller
{
    public function __construct(
        private readonly MenuAddonService $service
    ) {}

    /**
     * GET /api/owner/menu-addons
     */
    public function index(Request $request): JsonResponse
    {
        $result = $this->service->listAddons($request->user());

        return response()->json($result, $result['success'] ? 200 : ($result['http'] ?? 400));
    }

    /**
     * POST /api/owner/menu-addons
     */
    public function store(MenuAddonRequest $request): JsonResponse
    {
        $result = $this->service->createAddon($request->user(), $request->validated());

        return response()->json($result, $result['success'] ? 201 : ($result['http'] ?? 400));
    }

    /**
     * PATCH /api/owner/menu-addons/{uuid}
     */
    public function update(MenuAddonRequest $request, string $uuid): JsonResponse
    {
        $result = $this->service->updateAddon($request->user(), $uuid, $request->validated());

        return response()->json($result, $result['success'] ? 200 : ($result['http'] ?? 400));
    }

    /**
     * DELETE /api/owner/menu-addons/{uuid}
     */
    public function destroy(Request $request, string $uuid): JsonResponse
    {
        $result = $this->service->deleteAddon($request->user(), $uuid);

        return response()->json($result, $result['success'] ? 200 : ($result['http'] ?? 400));
    }
}
