<?php

namespace App\Http\Controllers;

use App\Http\Requests\ListReservationsRequest;
use App\Http\Requests\StoreReservationRequest;
use App\Http\Requests\UpdateReservationRequest;
use App\Http\Requests\UpdateReservationStatusRequest;
use App\Services\ReservationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Reservations of a branch — shared by owner and manager dashboards:
 *   /api/owner/branches/{branchUuid}/reservations/...
 *   /api/manager/branches/{branchUuid}/reservations/...
 *
 * `branch.access` has already authorised the branch and stored it on the request.
 */
class ReservationController extends Controller
{
    public function __construct(
        private readonly ReservationService $service
    ) {}

    /**
     * GET .../reservations
     */
    public function index(ListReservationsRequest $request): JsonResponse
    {
        return $this->respond($this->service->list(
            $request->attributes->get('branch'),
            $request->validated()
        ));
    }

    /**
     * POST .../reservations
     */
    public function store(StoreReservationRequest $request): JsonResponse
    {
        return $this->respond($this->service->create(
            $request->user(),
            $request->attributes->get('branch'),
            $request->validated()
        ), 201);
    }

    /**
     * GET .../reservations/{reservationUuid}
     */
    public function show(Request $request, string $branchUuid, string $reservationUuid): JsonResponse
    {
        return $this->respond($this->service->show($request->attributes->get('branch'), $reservationUuid));
    }

    /**
     * PATCH .../reservations/{reservationUuid}
     */
    public function update(UpdateReservationRequest $request, string $branchUuid, string $reservationUuid): JsonResponse
    {
        return $this->respond($this->service->update(
            $request->attributes->get('branch'),
            $reservationUuid,
            $request->validated()
        ));
    }

    /**
     * POST .../reservations/{reservationUuid}/status
     */
    public function updateStatus(UpdateReservationStatusRequest $request, string $branchUuid, string $reservationUuid): JsonResponse
    {
        return $this->respond($this->service->updateStatus(
            $request->attributes->get('branch'),
            $reservationUuid,
            $request->validated('status')
        ));
    }
}
