<?php

namespace App\Repository;

use App\Models\BranchTable;
use App\Models\CafeOpeningHour;
use App\Models\Reservation;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Every lookup is scoped through table -> floor plan -> branch. Removed tables
 * and plans are included so past bookings stay visible.
 */
class ReservationRepository
{
    public function paginateForBranch(int $branchId, array $filters, int $perPage): LengthAwarePaginator
    {
        return $this->branchScope($branchId)
            ->when($filters['date'] ?? null, fn (Builder $q, $d) => $q->whereDate('reservation_date', $d))
            ->when($filters['from'] ?? null, fn (Builder $q, $d) => $q->whereDate('reservation_date', '>=', $d))
            ->when($filters['to'] ?? null, fn (Builder $q, $d) => $q->whereDate('reservation_date', '<=', $d))
            ->when($filters['status'] ?? null, fn (Builder $q, $s) => $q->where('status', $s))
            ->when($filters['table_uuid'] ?? null, fn (Builder $q, $u) => $q->whereHas('table', fn ($t) => $t->where('uuid', $u)))
            ->when($filters['floor_plan_uuid'] ?? null, fn (Builder $q, $u) => $q->whereHas(
                'table.floorPlan', fn ($p) => $p->withTrashed()->where('uuid', $u)
            ))
            ->when($filters['search'] ?? null, fn (Builder $q, $s) => $q->where(function (Builder $w) use ($s) {
                $w->where('customer_name', 'like', "%{$s}%")->orWhere('customer_phone', 'like', "%{$s}%");
            }))
            ->orderBy('reservation_date')
            ->orderBy('reservation_id')
            ->paginate($perPage);
    }

    public function findForBranch(string $reservationUuid, int $branchId): ?Reservation
    {
        return $this->branchScope($branchId)->where('uuid', $reservationUuid)->first();
    }

    public function create(array $data): Reservation
    {
        return Reservation::create($data);
    }

    /** Locks the table row for the rest of the transaction so two bookings can't both pass the overlap check. */
    public function lockTable(BranchTable $table): BranchTable
    {
        return BranchTable::withTrashed()->where('table_id', $table->table_id)->lockForUpdate()->first();
    }

    public function findOverlap(int $tableId, Carbon $start, Carbon $end, ?int $ignoreReservationId = null): ?Reservation
    {
        return Reservation::blocking()
            ->where('table_id', $tableId)
            ->overlapping($start, $end)
            ->when($ignoreReservationId, fn (Builder $q, $id) => $q->where('reservation_id', '!=', $ignoreReservationId))
            ->orderBy('reservation_date')
            ->first();
    }

    /** @return Collection<string, CafeOpeningHour> keyed by day name */
    public function openingHours(int $cafeId): Collection
    {
        return CafeOpeningHour::where('cafe_id', $cafeId)->get()->keyBy('day_of_week');
    }

    private function branchScope(int $branchId): Builder
    {
        return Reservation::query()
            ->whereHas('table', fn ($t) => $t->withTrashed()->whereHas(
                'floorPlan', fn ($p) => $p->withTrashed()->where('branch_id', $branchId)
            ))
            ->with([
                'table.floorPlan' => fn ($q) => $q->withTrashed(),
                'creator',
            ]);
    }
}
