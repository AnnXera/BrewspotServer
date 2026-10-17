<?php

namespace App\Http\Controllers;

use App\Models\ServingSpoilageLog;
use App\Services\SpoilageService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Spoiled servings.
 *   Register (device token, pos.device):  /api/pos/device/spoilage
 *   Dashboard (branch.access):             /api/{owner|manager}/branches/{branchUuid}/servings/spoilage
 */
class SpoilageController extends Controller
{
    public function __construct(
        private readonly SpoilageService $service
    ) {}

    // ── On the device ────────────────────────────────────────────────────

    /**
     * GET /api/pos/device/spoilage — today's entries at the register's branch.
     */
    public function deviceIndex(Request $request): JsonResponse
    {
        return $this->respond($this->service->listForDevice($request->user()));
    }

    /**
     * POST /api/pos/device/spoilage
     */
    public function deviceStore(Request $request): JsonResponse
    {
        $data = $request->validate([
            'menu_item_uuid' => ['required', 'string'],
            'size_uuid'      => ['nullable', 'string'], // required for items that come in sizes
            'quantity'       => ['required', 'integer', 'min:1', 'max:1000'],
            'reason'         => ['nullable', 'string', Rule::in(ServingSpoilageLog::REASONS)],
        ]);

        return $this->respond($this->service->logOnDevice(
            $request->user(),
            $data['menu_item_uuid'],
            $data['size_uuid'] ?? null,
            (int) $data['quantity'],
            $data['reason'] ?? null
        ), 201);
    }

    // ── Dashboard ────────────────────────────────────────────────────────

    /**
     * GET .../branches/{branchUuid}/servings/spoilage — today's entries, voided ones included.
     */
    public function index(Request $request): JsonResponse
    {
        return $this->respond($this->service->listForBranch($request->attributes->get('branch')));
    }

    /**
     * POST .../branches/{branchUuid}/servings/spoilage/{logUuid}/void
     */
    public function void(Request $request, string $branchUuid, string $logUuid): JsonResponse
    {
        return $this->respond($this->service->void(
            $request->user(),
            $request->attributes->get('branch'),
            $logUuid
        ));
    }
}
