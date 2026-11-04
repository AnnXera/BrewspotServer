<?php

namespace App\Repository;

use App\Models\InventoryServing;
use App\Models\MenuItem;
use App\Models\ServingSpoilageLog;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

class SpoilageRepository
{
    public function findMenuItemWithSizes(string $uuid, int $cafeId): ?MenuItem
    {
        return MenuItem::where('uuid', $uuid)
            ->where('cafe_id', $cafeId)
            ->with('sizes')
            ->first();
    }

    /** The item's serving row for the date, locked. Call inside a transaction. */
    public function lockServingForDate(int $itemId, int $branchId, Carbon $date): ?InventoryServing
    {
        return InventoryServing::where('men_item_id', $itemId)
            ->where('branch_id', $branchId)
            ->whereDate('date', $date)
            ->lockForUpdate()
            ->first();
    }

    /** Call inside a transaction. */
    public function lockServing(int $servingsId): ?InventoryServing
    {
        return InventoryServing::whereKey($servingsId)->lockForUpdate()->first();
    }

    public function createLog(array $data): ServingSpoilageLog
    {
        return ServingSpoilageLog::create($data);
    }

    /** A spoilage entry on the branch's servings for the date, locked. Call inside a transaction. */
    public function lockLogForDate(string $uuid, int $branchId, Carbon $date): ?ServingSpoilageLog
    {
        return ServingSpoilageLog::where('uuid', $uuid)
            ->whereHas('serving', fn ($q) => $q
                ->where('branch_id', $branchId)
                ->whereDate('date', $date))
            ->lockForUpdate()
            ->first();
    }

    public function listForDate(int $branchId, Carbon $date, array $with): Collection
    {
        return ServingSpoilageLog::whereHas('serving', fn ($q) => $q
                ->where('branch_id', $branchId)
                ->whereDate('date', $date))
            ->with($with)
            ->latest('spoilage_log_id')
            ->get();
    }
}
