<?php

namespace App\Http\Controllers;

use App\Http\Requests\ListReservationsRequest;
use App\Http\Requests\UpdateTableStatusRequest;
use App\Models\PosDevice;
use App\Services\FloorPlanService;
use App\Services\ReservationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * Floor plan on the register (device token + `reservations` plan feature).
 * Read-only apart from table status. The register must be unlocked by staff
 * first, since reservations hold customer details.
 */
class PosFloorPlanController extends Controller
{
    public function __construct(
        private readonly FloorPlanService $plans,
        private readonly ReservationService $reservations
    ) {}

    /**
     * GET /api/pos/device/floor-plan — the active plan with live table status.
     */
    public function floorPlan(Request $request): JsonResponse
    {
        return $this->unlocked($request) ?? $this->respond($this->plans->activeForRegister($request->user()->branch));
    }

    /**
     * PATCH /api/pos/device/tables/{tableUuid}/status
     */
    public function tableStatus(UpdateTableStatusRequest $request, string $tableUuid): JsonResponse
    {
        return $this->unlocked($request) ?? $this->respond($this->plans->updateTableStatus(
            $request->user()->branch,
            $tableUuid,
            $request->validated('status')
        ));
    }

    /**
     * GET /api/pos/device/reservations — today's by default; ?date=YYYY-MM-DD for another day.
     */
    public function reservations(ListReservationsRequest $request): JsonResponse
    {
        if ($locked = $this->unlocked($request)) {
            return $locked;
        }

        $filters = $request->validated();

        if (empty($filters['date']) && empty($filters['from']) && empty($filters['to'])) {
            $filters['date'] = Carbon::now()->toDateString();
        }

        return $this->respond($this->reservations->list($request->user()->branch, $filters));
    }

    /** A 423 response while nobody has unlocked the register, otherwise null. */
    private function unlocked(Request $request): ?JsonResponse
    {
        /** @var PosDevice $device */
        $device = $request->user();

        if ($device->active_staff_id) {
            return null;
        }

        return response()->json([
            'success'         => false,
            'message'         => 'Unlock the register with your PIN first.',
            'requires_unlock' => true,
        ], 423);
    }
}
