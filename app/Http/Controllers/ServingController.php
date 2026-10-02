<?php

namespace App\Http\Controllers;

use App\Http\Requests\SaveCategoryServingsRequest;
use App\Http\Requests\StoreServingRequest;
use App\Http\Requests\UpdateServingRequest;
use App\Services\ServingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Today's servings of a branch — shared by owner and manager dashboards:
 *   /api/owner/branches/{branchUuid}/servings/...
 *   /api/manager/branches/{branchUuid}/servings/...
 *
 * `branch.access` has already authorised the branch and stored it on the request. */
class ServingController extends Controller
{
    public function __construct(
        private readonly ServingService $service
    ) {}

    /**
     * GET .../branches/{branchUuid}/servings
     */
    public function index(Request $request): JsonResponse
    {
        return $this->respond($this->service->listToday($request->attributes->get('branch')));
    }

    /**
     * GET .../branches/{branchUuid}/servings/available-items — items not yet on today's list.
     */
    public function availableItems(Request $request): JsonResponse
    {
        return $this->respond($this->service->availableItems($request->attributes->get('branch')));
    }

    /**
     * POST .../branches/{branchUuid}/servings
     */
    public function store(StoreServingRequest $request): JsonResponse
    {
        return $this->respond($this->service->create(
            $request->user(),
            $request->attributes->get('branch'),
            $request->validated('menu_item_uuid'),
            (int) $request->validated('expected_servings')
        ), 201);
    }

    /**
     * PUT .../branches/{branchUuid}/servings/categories/{categoryUuid}/items — Save Changes on a category page.
     */
    public function saveCategoryItems(SaveCategoryServingsRequest $request, string $branchUuid, string $categoryUuid): JsonResponse
    {
        return $this->respond($this->service->saveCategoryItems(
            $request->user(),
            $request->attributes->get('branch'),
            $categoryUuid,
            $request->validated('items')
        ));
    }

    /**
     * PATCH .../branches/{branchUuid}/servings/{servingUuid}
     */
    public function update(UpdateServingRequest $request, string $branchUuid, string $servingUuid): JsonResponse
    {
        return $this->respond($this->service->update(
            $request->user(),
            $request->attributes->get('branch'),
            $servingUuid,
            $request->validated()
        ));
    }

    /**
     * DELETE .../branches/{branchUuid}/servings/{servingUuid}
     */
    public function destroy(Request $request, string $branchUuid, string $servingUuid): JsonResponse
    {
        return $this->respond($this->service->delete(
            $request->user(),
            $request->attributes->get('branch'),
            $servingUuid
        ));
    }
}
