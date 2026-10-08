<?php

namespace App\Http\Controllers;

use App\Http\Requests\ChangePinOnDeviceRequest;
use App\Http\Requests\RegisterPosDeviceRequest;
use App\Http\Requests\UnlockPosRequest;
use App\Services\PosDeviceService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PosDeviceController extends Controller
{
    public function __construct(
        private readonly PosDeviceService $service
    ) {}

    // ── Setup (owner / manager token) ────────────────────────────────────

    /**
     * GET /api/pos/setup/branches — branches the signed-in owner/manager can register a device for.
     */
    public function setupBranches(Request $request): JsonResponse
    {
        return $this->respond($this->service->registerableBranches($request->user()));
    }

    /**
     * POST /api/pos/setup — register this device; returns the device token.
     */
    public function register(RegisterPosDeviceRequest $request): JsonResponse
    {
        return $this->respond($this->service->register(
            $request->user(),
            $request->validated('branch_uuid'),
            $request->validated('name')
        ), 201);
    }

    // ── Dashboard (owner / manager, branch.access) ───────────────────────

    /**
     * GET /api/{owner|manager}/branches/{branchUuid}/pos-devices
     */
    public function index(Request $request): JsonResponse
    {
        return $this->respond($this->service->listForBranch($request->attributes->get('branch')));
    }

    /**
     * DELETE /api/{owner|manager}/branches/{branchUuid}/pos-devices/{deviceUuid}
     */
    public function destroy(Request $request, string $branchUuid, string $deviceUuid): JsonResponse
    {
        return $this->respond($this->service->revokeForBranch(
            $request->user(),
            $request->attributes->get('branch'),
            $deviceUuid
        ));
    }

    // ── On the device (device token, pos.device) ─────────────────────────

    /**
     * GET /api/pos/device
     */
    public function current(Request $request): JsonResponse
    {
        return $this->respond($this->service->current($request->user()));
    }

    /**
     * DELETE /api/pos/device — the device unregisters itself.
     */
    public function unregister(Request $request): JsonResponse
    {
        return $this->respond($this->service->unregisterSelf($request->user()));
    }

    /**
     * GET /api/pos/device/staff — names on the lock screen.
     */
    public function staff(Request $request): JsonResponse
    {
        return $this->respond($this->service->lockScreenStaff($request->user()));
    }

    /**
     * POST /api/pos/device/staff/{userUuid}/unlock
     */
    public function unlock(UnlockPosRequest $request, string $userUuid): JsonResponse
    {
        return $this->respond($this->service->unlock(
            $request->user(),
            $userUuid,
            $request->validated('pin')
        ));
    }

    /**
     * GET /api/pos/device/menu
     */
    public function menu(Request $request): JsonResponse
    {
        return $this->respond($this->service->menu($request->user()));
    }

    /**
     * POST /api/pos/device/checkout
     */
    public function checkout(Request $request): JsonResponse
    {
        $request->validate([
            'items' => 'required|array|min:1',
            'items.*.uuid' => 'required|string',
            'items.*.quantity' => 'required|integer|min:1',
            'items.*.sugar_level' => 'nullable|integer|min:0|max:100',
            'items.*.addons' => 'nullable|array',
            'payment_method' => 'required|string',
            'amount_tendered' => 'required|numeric|min:0',
            'reference_number' => 'nullable|string',
            'discount_type' => 'nullable|string|in:none,PWD,Senior,VIP',
            'discount_amount' => 'nullable|numeric|min:0',
        ]);

        return $this->respond($this->service->checkout(
            $request->user(),
            $request->input('items'),
            $request->input('payment_method'),
            $request->input('amount_tendered'),
            $request->input('reference_number'),
            $request->input('discount_type'),
            (float) $request->input('discount_amount', 0)
        ));
    }

    /**
     * GET /api/pos/device/transactions
     */
    public function transactions(Request $request): JsonResponse
    {
        return $this->respond($this->service->transactions($request->user()));
    }

    /**
     * POST /api/pos/device/staff/{userUuid}/change-pin — replace a temporary PIN, then unlock.
     */
    public function changePin(ChangePinOnDeviceRequest $request, string $userUuid): JsonResponse
    {
        return $this->respond($this->service->changePinAndUnlock(
            $request->user(),
            $userUuid,
            $request->validated('current_pin'),
            $request->validated('new_pin')
        ));
    }

    /**
     * POST /api/pos/device/lock
     */
    public function lock(Request $request): JsonResponse
    {
        return $this->respond($this->service->lock($request->user()));
    }
}
