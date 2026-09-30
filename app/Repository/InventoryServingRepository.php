<?php

namespace App\Repository;

use App\Models\CafeBranch;
use App\Models\CategoryBranch;
use App\Models\InventoryServing;
use App\Models\MenuBranch;
use App\Models\MenuItem;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

class InventoryServingRepository
{
    public function listForDate(int $branchId, Carbon $date): Collection
    {
        return InventoryServing::where('branch_id', $branchId)
            ->whereDate('date', $date)
            ->with(['menuItem' => fn ($q) => $q->withTrashed()->with('category')])
            ->get()
            ->sortBy(fn (InventoryServing $s) => strtolower($s->menuItem?->menu_name ?? ''))
            ->values();
    }

    public function findForDate(string $uuid, int $branchId, Carbon $date): ?InventoryServing
    {
        return InventoryServing::where('uuid', $uuid)
            ->where('branch_id', $branchId)
            ->whereDate('date', $date)
            ->with(['menuItem' => fn ($q) => $q->withTrashed()->with('category')])
            ->first();
    }

    public function existsForDate(int $itemId, int $branchId, Carbon $date): bool
    {
        return InventoryServing::where('men_item_id', $itemId)
            ->where('branch_id', $branchId)
            ->whereDate('date', $date)
            ->exists();
    }

    public function findMenuItemForCafe(string $uuid, int $cafeId): ?MenuItem
    {
        return MenuItem::where('uuid', $uuid)
            ->where('cafe_id', $cafeId)
            ->with('category')
            ->first();
    }

    /**
     * Cafe menu items that are effectively available at the branch
     * (item override, else item default; the item's category must be available too).
     */
    public function listAvailableItemsForBranch(CafeBranch $branch): Collection
    {
        $items = MenuItem::where('cafe_id', $branch->cafe_id)
            ->with('category')
            ->orderBy('menu_name')
            ->get();

        $itemOverrides = MenuBranch::where('branch_id', $branch->branch_id)
            ->whereIn('men_item_id', $items->pluck('men_item_id'))
            ->get()
            ->keyBy('men_item_id');

        $categoryOverrides = CategoryBranch::where('branch_id', $branch->branch_id)
            ->get()
            ->keyBy('men_category_id');

        return $items->filter(function (MenuItem $item) use ($itemOverrides, $categoryOverrides) {
            $itemAvailable = $itemOverrides->get($item->men_item_id)?->is_available ?? $item->is_available;

            if (! $itemAvailable) {
                return false;
            }

            if (! $item->category) {
                return true;
            }

            return $categoryOverrides->get($item->men_category_id)?->is_available ?? $item->category->is_available;
        })->values();
    }

    public function create(int $itemId, int $branchId, Carbon $date, int $expectedServings): InventoryServing
    {
        $serving = InventoryServing::create([
            'men_item_id'       => $itemId,
            'branch_id'         => $branchId,
            'date'              => $date->toDateString(),
            'expected_servings' => $expectedServings,
        ]);

        return $serving->load(['menuItem' => fn ($q) => $q->withTrashed()->with('category')]);
    }

    public function update(InventoryServing $serving, array $payload): InventoryServing
    {
        $serving->update($payload);

        return $serving;
    }

    public function delete(InventoryServing $serving): void
    {
        $serving->delete();
    }
}
