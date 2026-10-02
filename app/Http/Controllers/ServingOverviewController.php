<?php

namespace App\Http\Controllers;

use App\Http\Requests\ServingOverviewRequest;
use App\Services\ServingOverviewService;
use Illuminate\Http\JsonResponse;

/**
 * Servings Management screen data (read-only), shared by owner and manager:
 *   /api/owner/branches/{branchUuid}/servings/...
 *   /api/manager/branches/{branchUuid}/servings/...
 *
 * `branch.access` has already authorised the branch and stored it on the request.
 */
class ServingOverviewController extends Controller
{
    public function __construct(
        private readonly ServingOverviewService $service
    ) {}

    /**
     * GET .../servings/categories?search=&sort=name|remaining_desc|remaining_asc&page=&per_page=
     */
    public function categories(ServingOverviewRequest $request): JsonResponse
    {
        return $this->respond($this->service->categories(
            $request->attributes->get('branch'),
            $request->validated('search'),
            $request->validated('sort') ?? 'name',
            (int) ($request->validated('page') ?? 1),
            (int) ($request->validated('per_page') ?? 6)
        ));
    }

    /**
     * GET .../servings/ingredients-used?search=&sort=name|quantity_desc|quantity_asc
     */
    public function ingredientsUsed(ServingOverviewRequest $request): JsonResponse
    {
        return $this->respond($this->service->ingredientsUsed(
            $request->attributes->get('branch'),
            $request->validated('search'),
            $request->validated('sort') ?? 'name'
        ));
    }

    /**
     * GET .../servings/log?page=&per_page=
     */
    public function log(ServingOverviewRequest $request): JsonResponse
    {
        return $this->respond($this->service->log(
            $request->attributes->get('branch'),
            (int) ($request->validated('per_page') ?? 20)
        ));
    }
}
