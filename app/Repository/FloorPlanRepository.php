<?php

namespace App\Repository;

use App\Models\BranchTable;
use App\Models\FloorPlan;
use App\Models\Reservation;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Every lookup is scoped by branch_id so a uuid from another branch is never reachable.
 */
class FloorPlanRepository
{
    public function listForBranch(int $branchId): Collection
    {
        return FloorPlan::where('branch_id', $branchId)
            ->withCount('tables')
            ->orderByDesc('is_active')
            ->orderBy('floorplan_name')
            ->get();
    }

    public function findForBranch(string $planUuid, int $branchId): ?FloorPlan
    {
        return FloorPlan::where('uuid', $planUuid)->where('branch_id', $branchId)->first();
    }

    public function findActiveForBranch(int $branchId): ?FloorPlan
    {
        return FloorPlan::where('branch_id', $branchId)->where('is_active', true)->first();
    }

    /** The first plan of a branch becomes the active one automatically. */
    public function create(int $branchId, array $data): FloorPlan
    {
        return FloorPlan::create([
            ...$data,
            'branch_id' => $branchId,
            'is_active' => ! FloorPlan::where('branch_id', $branchId)->exists(),
        ]);
    }

    public function update(FloorPlan $plan, array $data): FloorPlan
    {
        $plan->update($data);

        return $plan;
    }

    public function delete(FloorPlan $plan): void
    {
        $plan->delete();
    }

    /** Exactly one active plan per branch. */
    public function activate(FloorPlan $plan): void
    {
        DB::transaction(function () use ($plan) {
            FloorPlan::where('branch_id', $plan->branch_id)
                ->where('floor_plan_id', '!=', $plan->floor_plan_id)
                ->update(['is_active' => false]);

            $plan->update(['is_active' => true]);
        });
    }

    public function findTableForBranch(string $tableUuid, int $branchId): ?BranchTable
    {
        return BranchTable::where('uuid', $tableUuid)
            ->whereHas('floorPlan', fn ($q) => $q->where('branch_id', $branchId))
            ->first();
    }

    /** Pending/confirmed/seated bookings that still hold this table in the future. */
    public function hasUpcomingReservations(BranchTable|int $table): bool
    {
        return $this->upcomingReservationsQuery()
            ->where('table_id', $table instanceof BranchTable ? $table->table_id : $table)
            ->exists();
    }

    public function planHasUpcomingReservations(FloorPlan $plan): bool
    {
        return $this->upcomingReservationsQuery()
            ->whereHas('table', fn ($q) => $q->where('floorplan_id', $plan->floor_plan_id))
            ->exists();
    }

    /**
     * For the register: the next blocking reservation per table of the plan
     * that starts today (or is already in progress).
     *
     * @return Collection<int, Reservation> keyed by table_id
     */
    public function nextReservationsByTable(FloorPlan $plan): Collection
    {
        return $this->upcomingReservationsQuery()
            ->whereHas('table', fn ($q) => $q->where('floorplan_id', $plan->floor_plan_id))
            ->where('reservation_date', '<', Carbon::now()->endOfDay())
            ->orderBy('reservation_date')
            ->get()
            ->unique('table_id')
            ->keyBy('table_id');
    }

    private function upcomingReservationsQuery()
    {
        return Reservation::blocking()->where('reservation_end', '>', Carbon::now());
    }
}
