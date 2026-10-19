<?php

namespace App\Repository;

use App\Models\CafeBranch;
use App\Models\InventoryServing;
use App\Models\MenuItem;
use App\Models\Reservation;
use App\Models\Transaction;
use App\Models\TransactionItem;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Raw figures behind the owner Analytics page. Every query takes the branch ids
 * already scoped to one owner (and optionally one branch).
 */
class OwnerAnalyticsRepository
{
    /** Sale statuses that count as income, same as the POS reports. */
    public const SETTLED = ['completed', 'paid'];

    /**
     * The owner's branches, soft-deleted ones included (their past sales still count).
     */
    public function branchesByOwner(int $userId): Collection
    {
        return CafeBranch::withTrashed()
            ->whereHas('cafe', fn ($q) => $q->where('user_id', $userId))
            ->orderBy('branch_name')
            ->get(['branch_id', 'uuid', 'cafe_id', 'branch_name', 'branch_type', 'status', 'deleted_at']);
    }

    // ── Sales ────────────────────────────────────────────────────────────

    /**
     * @return object{orders: int, revenue: float, discounts: float, discounted_orders: int}
     */
    public function salesTotals(Collection $branchIds, Carbon $from, Carbon $to): object
    {
        return $this->settled($branchIds, $from, $to)
            ->selectRaw('COUNT(*) as orders,
                COALESCE(SUM(total_amount), 0) as revenue,
                COALESCE(SUM(discount_amount), 0) as discounts,
                SUM(CASE WHEN discount_amount > 0 THEN 1 ELSE 0 END) as discounted_orders')
            ->toBase()
            ->first();
    }

    public function itemsSold(Collection $branchIds, Carbon $from, Carbon $to): int
    {
        return (int) $this->settledItems($branchIds, $from, $to)->sum('transaction_items.quantity');
    }

    /**
     * Sales that did not settle (voided, refunded), reported beside the income.
     */
    public function unsettledTotals(Collection $branchIds, Carbon $from, Carbon $to): object
    {
        return Transaction::query()
            ->whereIn('branch_id', $branchIds)
            ->whereNotIn('status', self::SETTLED)
            ->whereBetween('created_at', [$from, $to])
            ->selectRaw('COUNT(*) as orders, COALESCE(SUM(total_amount), 0) as amount')
            ->toBase()
            ->first();
    }

    /**
     * Revenue and order count per calendar day, keyed by Y-m-d.
     */
    public function dailyTotals(Collection $branchIds, Carbon $from, Carbon $to): Collection
    {
        return $this->settled($branchIds, $from, $to)
            ->selectRaw('DATE(created_at) as day, COUNT(*) as orders, COALESCE(SUM(total_amount), 0) as revenue')
            ->groupBy('day')
            ->toBase()
            ->get()
            ->keyBy(fn ($row) => (string) $row->day);
    }

    /**
     * Orders and revenue per weekday (0 = Sunday) and hour of day.
     */
    public function hourlyTotals(Collection $branchIds, Carbon $from, Carbon $to): Collection
    {
        [$weekday, $hour] = $this->weekdayHourSql('created_at');

        return $this->settled($branchIds, $from, $to)
            ->selectRaw("{$weekday} as weekday, {$hour} as hour, COUNT(*) as orders, COALESCE(SUM(total_amount), 0) as revenue")
            ->groupBy('weekday', 'hour')
            ->toBase()
            ->get();
    }

    /**
     * hourlyTotals as a 7 x 24 grid (weekday 0 = Sunday), every cell present.
     */
    public function hourlyGrid(Collection $branchIds, Carbon $from, Carbon $to): array
    {
        $grid = [];
        for ($d = 0; $d < 7; $d++) {
            $grid[$d] = array_fill(0, 24, ['orders' => 0, 'revenue' => 0.0]);
        }

        foreach ($this->hourlyTotals($branchIds, $from, $to) as $row) {
            $grid[(int) $row->weekday][(int) $row->hour] = [
                'orders'  => (int) $row->orders,
                'revenue' => round((float) $row->revenue, 2),
            ];
        }

        return $grid;
    }

    public function paymentMethods(Collection $branchIds, Carbon $from, Carbon $to): Collection
    {
        return DB::table('payments')
            ->join('transactions', 'transactions.transaction_id', '=', 'payments.payable_id')
            ->where('payments.payable_type', Transaction::class)
            ->whereIn('transactions.branch_id', $branchIds)
            ->whereIn('transactions.status', self::SETTLED)
            ->whereBetween('transactions.created_at', [$from, $to])
            ->groupBy('payments.payment_method_type')
            ->selectRaw('payments.payment_method_type as method, COUNT(DISTINCT transactions.transaction_id) as orders, COALESCE(SUM(transactions.total_amount), 0) as amount')
            ->get();
    }

    public function discountTypes(Collection $branchIds, Carbon $from, Carbon $to): Collection
    {
        return $this->settled($branchIds, $from, $to)
            ->where('discount_amount', '>', 0)
            ->groupBy('discount_type')
            ->selectRaw('discount_type as type, COUNT(*) as orders, COALESCE(SUM(discount_amount), 0) as amount')
            ->toBase()
            ->get();
    }

    // ── Menu ─────────────────────────────────────────────────────────────

    /**
     * Orders, units and gross sales per menu item. Discounts sit on the whole sale, not the line.
     */
    public function itemSales(Collection $branchIds, Carbon $from, Carbon $to): Collection
    {
        return $this->settledItems($branchIds, $from, $to)
            ->join('menu_items', 'menu_items.men_item_id', '=', 'transaction_items.men_item_id')
            ->leftJoin('menu_categories', 'menu_categories.men_category_id', '=', 'menu_items.men_category_id')
            ->groupBy('menu_items.men_item_id', 'menu_items.uuid', 'menu_items.menu_name', 'menu_categories.uuid', 'menu_categories.name')
            ->selectRaw('menu_items.men_item_id, menu_items.uuid, menu_items.menu_name,
                menu_categories.uuid as category_uuid, menu_categories.name as category_name,
                COUNT(DISTINCT transactions.transaction_id) as orders,
                SUM(transaction_items.quantity) as quantity,
                SUM(transaction_items.quantity * transaction_items.unit_price) as sales')
            ->toBase()
            ->get();
    }

    /**
     * Items on the cafe's menu right now (available, not deleted).
     */
    public function liveMenuItems(Collection $cafeIds): Collection
    {
        return MenuItem::query()
            ->whereIn('menu_items.cafe_id', $cafeIds)
            ->where('menu_items.is_available', true)
            ->leftJoin('menu_categories', 'menu_categories.men_category_id', '=', 'menu_items.men_category_id')
            ->orderBy('menu_items.menu_name')
            ->get(['menu_items.men_item_id', 'menu_items.uuid', 'menu_items.menu_name', 'menu_categories.name as category_name']);
    }

    public function categoryOrders(Collection $branchIds, Carbon $from, Carbon $to): Collection
    {
        return $this->settledItems($branchIds, $from, $to)
            ->join('menu_items', 'menu_items.men_item_id', '=', 'transaction_items.men_item_id')
            ->leftJoin('menu_categories', 'menu_categories.men_category_id', '=', 'menu_items.men_category_id')
            ->groupBy('menu_categories.uuid', 'menu_categories.name')
            ->selectRaw('menu_categories.uuid as category_uuid, menu_categories.name as category_name,
                COUNT(DISTINCT transactions.transaction_id) as orders,
                SUM(transaction_items.quantity) as quantity,
                SUM(transaction_items.quantity * transaction_items.unit_price) as sales')
            ->toBase()
            ->get();
    }

    /**
     * Units per size name (the snapshot taken at sale time). Items without sizes are left out.
     */
    public function sizeSales(Collection $branchIds, Carbon $from, Carbon $to): Collection
    {
        return $this->settledItems($branchIds, $from, $to)
            ->whereNotNull('transaction_items.size_name')
            ->groupBy('transaction_items.size_name')
            ->selectRaw('transaction_items.size_name as name,
                SUM(transaction_items.quantity) as quantity,
                SUM(transaction_items.quantity * transaction_items.unit_price) as sales')
            ->toBase()
            ->get();
    }

    /**
     * Add-on servings: per-drink quantity times the drinks on the line.
     */
    public function addonSales(Collection $branchIds, Carbon $from, Carbon $to): Collection
    {
        return $this->settledItems($branchIds, $from, $to)
            ->join('transaction_item_addons', 'transaction_item_addons.transaction_item_id', '=', 'transaction_items.transaction_item_id')
            ->groupBy('transaction_item_addons.addon_name')
            ->selectRaw('transaction_item_addons.addon_name as name,
                COUNT(DISTINCT transaction_items.transaction_item_id) as line_count,
                SUM(transaction_item_addons.quantity * transaction_items.quantity) as quantity,
                SUM(transaction_item_addons.price * transaction_item_addons.quantity * transaction_items.quantity) as sales')
            ->toBase()
            ->get();
    }

    public function itemLineCount(Collection $branchIds, Carbon $from, Carbon $to): int
    {
        return $this->settledItems($branchIds, $from, $to)->count();
    }

    // ── Branches ─────────────────────────────────────────────────────────

    public function branchSales(Collection $branchIds, Carbon $from, Carbon $to): Collection
    {
        return $this->settled($branchIds, $from, $to)
            ->groupBy('branch_id')
            ->selectRaw('branch_id, COUNT(*) as orders, COALESCE(SUM(total_amount), 0) as revenue')
            ->toBase()
            ->get()
            ->keyBy('branch_id');
    }

    // ── Operations ───────────────────────────────────────────────────────

    /**
     * Sales rung up per staff member. Sales with no staff attached are grouped under null.
     */
    public function staffSales(Collection $branchIds, Carbon $from, Carbon $to): Collection
    {
        return Transaction::query()
            ->leftJoin('cafe_staff', 'cafe_staff.staff_id', '=', 'transactions.staff_id')
            ->leftJoin('users', 'users.user_id', '=', 'cafe_staff.user_id')
            ->leftJoin('cafe_branches', 'cafe_branches.branch_id', '=', 'transactions.branch_id')
            ->whereIn('transactions.branch_id', $branchIds)
            ->whereIn('transactions.status', self::SETTLED)
            ->whereBetween('transactions.created_at', [$from, $to])
            ->groupBy('cafe_staff.uuid', 'cafe_staff.position', 'users.firstname', 'users.lastname', 'cafe_branches.branch_name')
            ->selectRaw('cafe_staff.uuid, cafe_staff.position, users.firstname, users.lastname,
                cafe_branches.branch_name,
                COUNT(*) as orders, COALESCE(SUM(transactions.total_amount), 0) as revenue,
                COALESCE(SUM(transactions.discount_amount), 0) as discounts')
            ->toBase()
            ->get();
    }

    /**
     * Daily servings planned vs sold vs spoiled, per menu item. Days of a
     * deleted item where nothing was sold or spoiled are left out (a plan
     * that never ran); days with activity stay, flagged `removed`.
     */
    public function servings(Collection $branchIds, Carbon $from, Carbon $to): Collection
    {
        return InventoryServing::query()
            ->join('menu_items', 'menu_items.men_item_id', '=', 'inventory_servings.men_item_id')
            ->whereIn('inventory_servings.branch_id', $branchIds)
            ->whereDate('inventory_servings.date', '>=', $from->toDateString())
            ->whereDate('inventory_servings.date', '<=', $to->toDateString())
            ->where(fn ($q) => $q
                ->whereNull('menu_items.deleted_at')
                ->orWhere('inventory_servings.servings_sold', '>', 0)
                ->orWhere('inventory_servings.spoilage_qty', '>', 0))
            ->groupBy('menu_items.uuid', 'menu_items.menu_name')
            ->selectRaw('menu_items.uuid, menu_items.menu_name,
                MAX(CASE WHEN menu_items.deleted_at IS NULL THEN 0 ELSE 1 END) as removed,
                SUM(inventory_servings.expected_servings) as expected,
                SUM(inventory_servings.servings_sold) as sold,
                SUM(inventory_servings.spoilage_qty) as spoilage,
                MAX(menu_items.base_price) as base_price,
                SUM(CASE WHEN inventory_servings.is_sold_out THEN 1 ELSE 0 END) as sold_out_days,
                COUNT(*) as days')
            ->toBase()
            ->get();
    }

    /**
     * Spoiled servings in a window, for the comparison with the previous period.
     */
    public function spoilageTotal(Collection $branchIds, Carbon $from, Carbon $to): int
    {
        return (int) $this->servingsInWindow($branchIds, $from, $to)->sum('inventory_servings.spoilage_qty');
    }

    /**
     * Spoilage entries logged on the registers, per reason. Voided entries are
     * grouped apart (reason null) so they can be reported without counting.
     *
     * @return Collection<int, object{reason: ?string, voided: int, entries: int, quantity: int}>
     */
    public function spoilageByReason(Collection $branchIds, Carbon $from, Carbon $to): Collection
    {
        return $this->servingsInWindow($branchIds, $from, $to)
            ->join('serving_spoilage_logs', 'serving_spoilage_logs.servings_id', '=', 'inventory_servings.servings_id')
            ->groupByRaw('CASE WHEN serving_spoilage_logs.voided_at IS NULL THEN serving_spoilage_logs.reason END, CASE WHEN serving_spoilage_logs.voided_at IS NULL THEN 0 ELSE 1 END')
            ->selectRaw('CASE WHEN serving_spoilage_logs.voided_at IS NULL THEN serving_spoilage_logs.reason END as reason,
                CASE WHEN serving_spoilage_logs.voided_at IS NULL THEN 0 ELSE 1 END as voided,
                COUNT(*) as entries,
                SUM(serving_spoilage_logs.quantity) as quantity')
            ->toBase()
            ->get();
    }

    /**
     * Counted (not voided) spoilage entries per menu item: servings and their
     * value at the price kept on each entry. Entries from before prices were
     * kept fall back to the item's base price.
     *
     * @return Collection<string, object{uuid: string, quantity: int, value: string}> keyed by item uuid
     */
    public function spoilageLogValues(Collection $branchIds, Carbon $from, Carbon $to): Collection
    {
        return $this->servingsInWindow($branchIds, $from, $to)
            ->join('serving_spoilage_logs', 'serving_spoilage_logs.servings_id', '=', 'inventory_servings.servings_id')
            ->join('menu_items', 'menu_items.men_item_id', '=', 'inventory_servings.men_item_id')
            ->whereNull('serving_spoilage_logs.voided_at')
            ->groupBy('menu_items.uuid')
            ->selectRaw('menu_items.uuid,
                SUM(serving_spoilage_logs.quantity) as quantity,
                SUM(serving_spoilage_logs.quantity * COALESCE(serving_spoilage_logs.unit_value, menu_items.base_price)) as value')
            ->toBase()
            ->get()
            ->keyBy('uuid');
    }

    private function servingsInWindow(Collection $branchIds, Carbon $from, Carbon $to): Builder
    {
        return InventoryServing::query()
            ->whereIn('inventory_servings.branch_id', $branchIds)
            ->whereDate('inventory_servings.date', '>=', $from->toDateString())
            ->whereDate('inventory_servings.date', '<=', $to->toDateString());
    }

    /**
     * Ingredients drawn by sales (recipe snapshot at sale time), per name and unit.
     */
    public function ingredientUsage(Collection $branchIds, Carbon $from, Carbon $to): Collection
    {
        return $this->settledItems($branchIds, $from, $to)
            ->join('ingredient_consumption_logs', 'ingredient_consumption_logs.transaction_item_id', '=', 'transaction_items.transaction_item_id')
            ->groupBy('ingredient_consumption_logs.ingredient_name', 'ingredient_consumption_logs.unit')
            ->selectRaw('ingredient_consumption_logs.ingredient_name as name, ingredient_consumption_logs.unit,
                SUM(ingredient_consumption_logs.quantity_consumed) as quantity,
                COUNT(DISTINCT transaction_items.men_item_id) as items')
            ->toBase()
            ->get();
    }

    /**
     * Reservations booked for the window (by reservation date, not creation date).
     */
    public function reservations(Collection $branchIds, Carbon $from, Carbon $to): Collection
    {
        return Reservation::query()
            ->join('branch_tables', 'branch_tables.table_id', '=', 'reservations.table_id')
            ->join('floor_plans', 'floor_plans.floor_plan_id', '=', 'branch_tables.floorplan_id')
            ->whereIn('floor_plans.branch_id', $branchIds)
            ->whereBetween('reservations.reservation_date', [$from, $to])
            ->get(['reservations.status', 'reservations.party_size', 'reservations.reservation_date']);
    }

    /**
     * Bookings still ahead (pending or confirmed) from $from to $to, soonest first.
     * Independent of the analytics period, which always ends today.
     */
    public function upcomingReservations(Collection $branchIds, Carbon $from, Carbon $to): Collection
    {
        return Reservation::query()
            ->join('branch_tables', 'branch_tables.table_id', '=', 'reservations.table_id')
            ->join('floor_plans', 'floor_plans.floor_plan_id', '=', 'branch_tables.floorplan_id')
            ->join('cafe_branches', 'cafe_branches.branch_id', '=', 'floor_plans.branch_id')
            ->whereIn('floor_plans.branch_id', $branchIds)
            ->whereIn('reservations.status', ['pending', 'confirmed'])
            ->whereBetween('reservations.reservation_date', [$from, $to])
            ->orderBy('reservations.reservation_date')
            ->get([
                'reservations.uuid', 'reservations.status', 'reservations.party_size', 'reservations.reservation_date',
                'branch_tables.table_name', 'cafe_branches.branch_name',
            ]);
    }

    // ── Helpers ──────────────────────────────────────────────────────────

    private function settled(Collection $branchIds, Carbon $from, Carbon $to): Builder
    {
        return Transaction::query()
            ->whereIn('branch_id', $branchIds)
            ->whereIn('status', self::SETTLED)
            ->whereBetween('created_at', [$from, $to]);
    }

    private function settledItems(Collection $branchIds, Carbon $from, Carbon $to): Builder
    {
        return TransactionItem::query()
            ->join('transactions', 'transactions.transaction_id', '=', 'transaction_items.transaction_id')
            ->whereIn('transactions.branch_id', $branchIds)
            ->whereIn('transactions.status', self::SETTLED)
            ->whereBetween('transactions.created_at', [$from, $to]);
    }

    /**
     * Weekday (0 = Sunday) and hour expressions for the running database.
     *
     * @return array{0: string, 1: string}
     */
    private function weekdayHourSql(string $column): array
    {
        return match (DB::getDriverName()) {
            'sqlite' => ["CAST(strftime('%w', {$column}) AS INTEGER)", "CAST(strftime('%H', {$column}) AS INTEGER)"],
            'pgsql'  => ["CAST(EXTRACT(DOW FROM {$column}) AS INTEGER)", "CAST(EXTRACT(HOUR FROM {$column}) AS INTEGER)"],
            default  => ["(DAYOFWEEK({$column}) - 1)", "HOUR({$column})"],
        };
    }
}
