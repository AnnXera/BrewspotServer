<?php

namespace App\Repository;

use App\Models\CafeBranch;
use App\Models\CategoryBranch;
use App\Models\Ingredient;
use App\Models\InventoryServing;
use App\Models\MenuBranch;
use App\Models\MenuCategory;
use App\Models\MenuItem;
use App\Models\TransactionItem;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

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

    public function findCategoryForCafe(string $uuid, int $cafeId): ?MenuCategory
    {
        return MenuCategory::where('uuid', $uuid)->where('cafe_id', $cafeId)->first();
    }

    /**
     * All menu items of a category, or of no category when $categoryId is null.
     */
    public function listItemsForCategory(int $cafeId, ?int $categoryId): Collection
    {
        return MenuItem::where('cafe_id', $cafeId)
            ->where('men_category_id', $categoryId)
            ->orderBy('menu_name')
            ->get();
    }

    /**
     * Cafe categories that are visible at the branch (branch override, else the category default).
     */
    public function listAvailableCategoriesForBranch(CafeBranch $branch): Collection
    {
        $overrides = CategoryBranch::where('branch_id', $branch->branch_id)->get()->keyBy('men_category_id');

        return MenuCategory::where('cafe_id', $branch->cafe_id)
            ->orderBy('name')
            ->get()
            ->filter(fn (MenuCategory $c) => $overrides->get($c->men_category_id)?->is_available ?? $c->is_available)
            ->values();
    }

    /**
     * Ingredients consumed by the branch's completed sales on a day, summed per
     * ingredient and unit (the name is the snapshot taken at sale time).
     *
     * @return Collection<int, object{name: string, unit: string, quantity: string}>
     */
    public function ingredientUsageForDate(int $branchId, Carbon $date): Collection
    {
        return DB::table('ingredient_consumption_logs as l')
            ->join('transaction_items as ti', 'ti.transaction_item_id', '=', 'l.transaction_item_id')
            ->join('transactions as t', 't.transaction_id', '=', 'ti.transaction_id')
            ->where('t.branch_id', $branchId)
            ->where('t.status', 'completed')
            ->whereBetween('t.created_at', [$date->copy()->startOfDay(), $date->copy()->endOfDay()])
            ->whereNotIn('l.unit', Ingredient::UNMEASURED_UNITS)
            ->groupBy('l.ingredient_name', 'l.unit')
            ->selectRaw('l.ingredient_name as name, l.unit, SUM(l.quantity_consumed) as quantity')
            ->get();
    }

    /**
     * Sold lines of the branch's completed transactions on a day, newest first.
     */
    public function salesLinesForDate(int $branchId, Carbon $date, int $perPage): LengthAwarePaginator
    {
        return TransactionItem::query()
            ->whereHas('transaction', fn ($q) => $q
                ->where('branch_id', $branchId)
                ->where('status', 'completed')
                ->whereBetween('created_at', [$date->copy()->startOfDay(), $date->copy()->endOfDay()]))
            ->with(['transaction', 'menuItem' => fn ($q) => $q->withTrashed()])
            ->latest('transaction_item_id')
            ->paginate($perPage);
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
