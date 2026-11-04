<?php

namespace App\Repository;

use App\Models\CafeStaff;
use App\Models\Reservation;
use App\Models\Transaction;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

class ManagerDashboardRepository
{
    // ── Reservations ─────────────────────────────────────────────────────

    /** Reservation dates (and status) from the given moment onward. */
    public function reservationDatesSince(int $branchId, Carbon $since): Collection
    {
        return $this->reservations($branchId)
            ->where('reservation_date', '>=', $since)
            ->get(['reservation_id', 'reservation_date', 'status']);
    }

    public function countUpcomingReservations(int $branchId, Carbon $now): int
    {
        return $this->reservations($branchId)
            ->where('reservation_date', '>=', $now)
            ->whereIn('status', ['pending', 'confirmed'])
            ->count();
    }

    /** @return array<string, int> status => count, for reservations on or after $since */
    public function countReservationsByStatusSince(int $branchId, Carbon $since): array
    {
        return $this->reservations($branchId)
            ->where('reservation_date', '>=', $since)
            ->selectRaw('status, COUNT(*) as aggregate')
            ->groupBy('status')
            ->pluck('aggregate', 'status')
            ->map(fn ($n) => (int) $n)
            ->all();
    }

    /** Most recently created reservations dated on or after $since. */
    public function latestReservationsSince(int $branchId, Carbon $since, int $limit): Collection
    {
        return $this->reservations($branchId)
            ->with(['table' => fn ($q) => $q->withTrashed(), 'creator'])
            ->where('reservation_date', '>=', $since)
            ->orderByDesc('created_at')
            ->orderByDesc('reservation_id')
            ->take($limit)
            ->get();
    }

    // ── Staff ────────────────────────────────────────────────────────────

    public function staffWithSchedules(int $branchId): Collection
    {
        return CafeStaff::where('branch_id', $branchId)
            ->with(['user.role', 'schedules'])
            ->get();
    }

    // ── Transactions ─────────────────────────────────────────────────────

    public function recentTransactions(int $branchId, int $limit): Collection
    {
        return $this->transactionList($branchId)->take($limit)->get();
    }

    /**
     * @param array{status?: ?string, search?: ?string, from?: ?string, to?: ?string} $filters
     */
    public function paginateTransactions(int $branchId, array $filters, int $perPage): LengthAwarePaginator
    {
        $query = $this->transactionList($branchId);

        if (! empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        if (! empty($filters['search'])) {
            $s = $filters['search'];
            $query->where(function (Builder $q) use ($s) {
                $q->where('receipt_number', 'like', "%{$s}%")
                  ->orWhere('walk_in_note', 'like', "%{$s}%")
                  ->orWhereHas('staff.user', function ($u) use ($s) {
                      $u->where('firstname', 'like', "%{$s}%")
                        ->orWhere('lastname', 'like', "%{$s}%");
                  });
            });
        }

        if (! empty($filters['from'])) {
            $query->whereDate('created_at', '>=', $filters['from']);
        }
        if (! empty($filters['to'])) {
            $query->whereDate('created_at', '<=', $filters['to']);
        }

        return $query->paginate($perPage);
    }

    /** Counts and completed revenue for one day, or null columns when there were none. */
    public function dayTotals(int $branchId, Carbon $day): ?Transaction
    {
        return Transaction::where('branch_id', $branchId)
            ->whereDate('created_at', $day)
            ->selectRaw("
                COUNT(*) as total_count,
                SUM(CASE WHEN status IN ('completed', 'paid') THEN 1 ELSE 0 END) as completed_count,
                SUM(CASE WHEN status = 'void' THEN 1 ELSE 0 END) as void_count,
                SUM(CASE WHEN status = 'refunded' THEN 1 ELSE 0 END) as refunded_count,
                COALESCE(SUM(CASE WHEN status IN ('completed', 'paid') THEN total_amount ELSE 0 END), 0) as completed_amount
            ")
            ->first();
    }

    public function findTransactionWithDetails(int $branchId, string $uuid): ?Transaction
    {
        return Transaction::where('branch_id', $branchId)
            ->where('uuid', $uuid)
            ->with(['staff.user', 'items.menuItem', 'items.addons', 'payments'])
            ->first();
    }

    // ── Shared ───────────────────────────────────────────────────────────

    /** Reservations on any of the branch's tables, trashed tables and floor plans included. */
    private function reservations(int $branchId): Builder
    {
        return Reservation::query()
            ->whereHas('table', fn ($t) => $t->withTrashed()->whereHas(
                'floorPlan', fn ($p) => $p->withTrashed()->where('branch_id', $branchId)
            ));
    }

    private function transactionList(int $branchId): Builder
    {
        return Transaction::query()
            ->where('branch_id', $branchId)
            ->with(['staff.user', 'payments'])
            ->withCount('items')
            ->orderBy('created_at', 'desc');
    }
}
