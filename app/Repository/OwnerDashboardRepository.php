<?php

namespace App\Repository;

use App\Models\CafeBranch;
use App\Models\Transaction;
use App\Models\TransactionItem;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Sales figures across every branch of one owner. Only completed sales count.
 */
class OwnerDashboardRepository
{
    /**
     * Includes soft-deleted branches: their past sales are still the owner's income.
     */
    public function branchIdsByOwner(int $userId): Collection
    {
        return CafeBranch::withTrashed()
            ->whereHas('cafe', fn ($q) => $q->where('user_id', $userId))
            ->pluck('branch_id');
    }

    /**
     * Live branches only, for the counts and the per-branch breakdown.
     */
    public function branchesByOwner(int $userId): Collection
    {
        return CafeBranch::whereHas('cafe', fn ($q) => $q->where('user_id', $userId))
            ->orderBy('branch_name')
            ->get(['branch_id', 'uuid', 'branch_name', 'branch_type', 'status']);
    }

    /**
     * @return array{orders: int, revenue: float}
     */
    public function lifetimeTotals(Collection $branchIds): array
    {
        $row = $this->completed($branchIds)
            ->selectRaw('COUNT(*) as orders, COALESCE(SUM(total_amount), 0) as revenue')
            ->first();

        return [
            'orders'  => (int) $row->orders,
            'revenue' => (float) $row->revenue,
        ];
    }

    /**
     * Revenue and order count per calendar day, keyed by Y-m-d.
     */
    public function dailyTotals(Collection $branchIds, Carbon $from, Carbon $to): Collection
    {
        return $this->completed($branchIds)
            ->whereBetween('created_at', [$from, $to])
            ->selectRaw('DATE(created_at) as day, COUNT(*) as orders, COALESCE(SUM(total_amount), 0) as revenue')
            ->groupBy('day')
            ->get()
            ->keyBy(fn ($row) => (string) $row->day);
    }

    /**
     * Orders, units and gross sales per menu item (discounts sit on the whole sale, not the line).
     */
    public function itemSales(Collection $branchIds, Carbon $from, Carbon $to): Collection
    {
        return TransactionItem::query()
            ->join('transactions', 'transactions.transaction_id', '=', 'transaction_items.transaction_id')
            ->join('menu_items', 'menu_items.men_item_id', '=', 'transaction_items.men_item_id')
            ->leftJoin('menu_categories', 'menu_categories.men_category_id', '=', 'menu_items.men_category_id')
            ->whereIn('transactions.branch_id', $branchIds)
            ->where('transactions.status', 'completed')
            ->whereBetween('transactions.created_at', [$from, $to])
            ->groupBy('menu_items.men_item_id', 'menu_items.uuid', 'menu_items.menu_name', 'menu_categories.uuid', 'menu_categories.name')
            ->selectRaw('menu_items.men_item_id, menu_items.uuid, menu_items.menu_name,
                menu_categories.uuid as category_uuid, menu_categories.name as category_name,
                COUNT(DISTINCT transactions.transaction_id) as orders,
                SUM(transaction_items.quantity) as quantity,
                SUM(transaction_items.quantity * transaction_items.unit_price) as sales')
            ->get();
    }

    /**
     * Per menu category: how many orders included it, plus its units and gross sales.
     * One order with two drinks from the same category counts once.
     */
    public function categoryOrders(Collection $branchIds, Carbon $from, Carbon $to): Collection
    {
        return TransactionItem::query()
            ->join('transactions', 'transactions.transaction_id', '=', 'transaction_items.transaction_id')
            ->join('menu_items', 'menu_items.men_item_id', '=', 'transaction_items.men_item_id')
            ->leftJoin('menu_categories', 'menu_categories.men_category_id', '=', 'menu_items.men_category_id')
            ->whereIn('transactions.branch_id', $branchIds)
            ->where('transactions.status', 'completed')
            ->whereBetween('transactions.created_at', [$from, $to])
            ->groupBy('menu_categories.uuid', 'menu_categories.name')
            ->selectRaw('menu_categories.uuid as category_uuid, menu_categories.name as category_name,
                COUNT(DISTINCT transactions.transaction_id) as orders,
                COUNT(DISTINCT transaction_items.men_item_id) as items,
                SUM(transaction_items.quantity) as quantity,
                SUM(transaction_items.quantity * transaction_items.unit_price) as sales')
            ->get();
    }

    /**
     * Revenue and order count per branch, keyed by branch_id.
     */
    public function branchSales(Collection $branchIds, Carbon $from, Carbon $to): Collection
    {
        return $this->completed($branchIds)
            ->whereBetween('created_at', [$from, $to])
            ->groupBy('branch_id')
            ->selectRaw('branch_id, COUNT(*) as orders, COALESCE(SUM(total_amount), 0) as revenue')
            ->get()
            ->keyBy('branch_id');
    }

    private function completed(Collection $branchIds)
    {
        return Transaction::query()
            ->whereIn('branch_id', $branchIds)
            ->where('status', 'completed');
    }
}
