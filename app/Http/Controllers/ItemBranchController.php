<?php

namespace App\Http\Controllers;

use App\Http\Requests\SetItemBranchAvailabilityRequest;
use App\Services\ItemBranchService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ItemBranchController extends Controller
{
    public function __construct(
        private readonly ItemBranchService $service
    ) {}

    /**
     * GET /api/owner/menu-items/{uuid}/branches-status
     * Every branch, with effective (inherited or overridden) availability.
     */
    public function status(Request $request, string $uuid): JsonResponse
    {
        $result = $this->service->listAllBranchesStatus($request->user(), $uuid);

        return response()->json($result, $result['success'] ? 200 : 404);
    }

    /**
     * PATCH /api/owner/menu-items/{itemUuid}/branches/{branchUuid}
     */
    public function update(SetItemBranchAvailabilityRequest $request, string $itemUuid, string $branchUuid): JsonResponse
    {
        $result = $this->service->setBranchAvailability(
            $request->user(),
            $itemUuid,
            $branchUuid,
            $request->validated('is_available')
        );

        return response()->json($result, $result['success'] ? 200 : 422);
    }
}
