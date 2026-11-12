<?php

namespace App\Repository;

use App\Models\ActivityLog;
use App\Models\Cafe;
use App\Models\CafeBranch;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

class ActivityLogRepository
{
    public function create(array $attributes): ActivityLog
    {
        return ActivityLog::create($attributes);
    }

    /** @return Collection<int, int> */
    public function cafeIdsByOwner(int $ownerId): Collection
    {
        return Cafe::where('user_id', $ownerId)->pluck('cafe_id');
    }

    /** Every branch of the owner's cafes, removed ones included (old log rows still point at them). */
    public function branchesByOwner(int $ownerId): Collection
    {
        return CafeBranch::withTrashed()
            ->whereHas('cafe', fn ($q) => $q->where('user_id', $ownerId))
            ->orderBy('branch_name')
            ->get(['branch_id', 'uuid', 'cafe_id', 'branch_name', 'deleted_at']);
    }

    /**
     * @param  array{cafe_ids: Collection, branch_id: ?int, cafe_wide_categories?: string[], from: Carbon, to: Carbon, role: ?string, search: ?string}  $scope
     */
    public function paginate(array $scope, ?string $category, int $perPage, int $page): LengthAwarePaginator
    {
        return $this->query($scope)
            ->when($category, fn (Builder $q) => $q->where('category', $category))
            ->with(['branch:branch_id,uuid,branch_name', 'device:device_id,uuid,name'])
            ->orderByDesc('created_at')
            ->orderByDesc('activity_log_id')
            ->paginate($perPage, ['*'], 'page', $page);
    }

    /**
     * Rows per category under the same filters, for the filter chips.
     *
     * @return Collection<string, int>
     */
    public function countsByCategory(array $scope): Collection
    {
        return $this->query($scope)
            ->selectRaw('category, COUNT(*) as total')
            ->groupBy('category')
            ->pluck('total', 'category')
            ->map(fn ($n) => (int) $n);
    }

    private function query(array $scope): Builder
    {
        // With a branch: that branch's rows, plus cafe-wide rows of these categories (e.g. menu changes).
        $cafeWide = $scope['cafe_wide_categories'] ?? [];

        return ActivityLog::query()
            ->whereIn('cafe_id', $scope['cafe_ids'])
            ->when($scope['branch_id'], fn (Builder $q, int $id) => $q->where(fn (Builder $w) => $w
                ->where('branch_id', $id)
                ->when($cafeWide, fn (Builder $o) => $o->orWhere(fn (Builder $c) => $c->whereNull('branch_id')->whereIn('category', $cafeWide)))
            ))
            ->whereBetween('created_at', [$scope['from'], $scope['to']])
            ->when($scope['role'], fn (Builder $q, string $role) => $q->where('actor_role', $role))
            ->when($scope['search'], function (Builder $q, string $search) {
                $like = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $search) . '%';
                $q->where(fn (Builder $w) => $w->where('actor_name', 'like', $like)->orWhere('description', 'like', $like));
            });
    }
}
