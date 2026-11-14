<?php

namespace App\Repository;

use App\Models\IngredientConsumptionLog;
use App\Models\InventoryServing;
use App\Models\MenuCategory;
use App\Models\MenuItem;
use App\Models\MenuRecipe;
use App\Models\Payment;
use App\Models\Transaction;
use App\Models\TransactionItem;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Sales rung up on the register: menu lookups at checkout, the transaction and its
 * lines, the day's serving counters, ingredient usage, and refunds.
 */
class PosSaleRepository
{
    // ── Menu ─────────────────────────────────────────────────────────────

    public function categoriesForCafe(int $cafeId): Collection
    {
        return MenuCategory::where('cafe_id', $cafeId)->orderBy('name')->get();
    }

    /** The item with its sizes and available add-ons (and their recipes), for pricing a cart line. */
    public function findMenuItemForSale(string $uuid, int $cafeId): ?MenuItem
    {
        return MenuItem::where('uuid', $uuid)
            ->where('cafe_id', $cafeId)
            ->with(['sizes', 'addons' => fn ($q) => $q->where('is_available', true)->with('recipes')])
            ->first();
    }

    // ── Checkout (call inside a transaction) ─────────────────────────────

    /**
     * Next receipt number for the branch, zero-padded to six digits. Locks the
     * branch's latest sale so two registers can't hand out the same number.
     */
    public function nextReceiptNumber(int $branchId): string
    {
        $latest = Transaction::where('branch_id', $branchId)
            ->lockForUpdate()
            ->orderBy('transaction_id', 'desc')
            ->first();

        $next = $latest && $latest->receipt_number && is_numeric($latest->receipt_number)
            ? (int) $latest->receipt_number + 1
            : (int) ($latest?->transaction_id ?? 0) + 1;

        return str_pad((string) $next, 6, '0', STR_PAD_LEFT);
    }

    public function createTransaction(array $data): Transaction
    {
        return Transaction::create($data);
    }

    public function createItem(Transaction $transaction, array $row): TransactionItem
    {
        return TransactionItem::create(array_merge($row, [
            'transaction_id' => $transaction->transaction_id,
        ]));
    }

    public function createItemAddon(TransactionItem $item, array $data): void
    {
        $item->addons()->create($data);
    }

    /**
     * The item's serving row for the date, locked; created empty (no expected
     * servings) if the item was sold without servings being set.
     */
    public function lockOrCreateServing(int $itemId, int $branchId, Carbon $date): InventoryServing
    {
        return $this->lockServing($itemId, $branchId, $date)
            ?? InventoryServing::create([
                'men_item_id'       => $itemId,
                'branch_id'         => $branchId,
                'date'              => $date->toDateString(),
                'expected_servings' => 0,
                'servings_sold'     => 0,
                'spoilage_qty'      => 0,
                'servings_refunded' => 0,
                'is_sold_out'       => false,
            ]);
    }

    public function lockServing(int $itemId, int $branchId, Carbon $date): ?InventoryServing
    {
        return InventoryServing::where('men_item_id', $itemId)
            ->where('branch_id', $branchId)
            ->whereDate('date', $date)
            ->lockForUpdate()
            ->first();
    }

    public function recordServingsSold(InventoryServing $serving, int $quantity): void
    {
        $serving->increment('servings_sold', $quantity);

        if ($serving->isUsedUp()) {
            $serving->update(['is_sold_out' => true]);
        }
    }

    public function saveServing(InventoryServing $serving): void
    {
        $serving->save();
    }

    /** Recipe rows shared by every size, plus the sold size's own rows. */
    public function recipesForSoldItem(int $itemId, ?int $sizeId): Collection
    {
        return MenuRecipe::where('men_item_id', $itemId)
            ->where(fn ($q) => $q->whereNull('men_size_id')->orWhere('men_size_id', $sizeId))
            ->get();
    }

    /** @param MenuRecipe|object $recipe any recipe row with ingredient_id, ingredient_name, quantity, unit */
    public function logConsumption(TransactionItem $item, object $recipe, float $multiplier): void
    {
        IngredientConsumptionLog::create([
            'transaction_item_id' => $item->transaction_item_id,
            'ingredient_id'       => $recipe->ingredient_id,
            'ingredient_name'     => $recipe->ingredient_name,
            'quantity_consumed'   => $recipe->quantity * $multiplier,
            'unit'                => $recipe->unit,
        ]);
    }

    public function recordPayment(Transaction $transaction, array $data): Payment
    {
        return Payment::create(array_merge($data, [
            'payable_type' => Transaction::class,
            'payable_id'   => $transaction->transaction_id,
        ]));
    }

    // ── Reading sales ────────────────────────────────────────────────────

    public function transactionsOn(int $branchId, Carbon $day): Collection
    {
        return Transaction::where('branch_id', $branchId)
            ->whereDate('created_at', $day)
            ->with(['staff.user'])
            ->withCount('items')
            ->orderBy('created_at')
            ->get();
    }

    public function monthTotal(int $branchId, Carbon $month, array $statuses): float
    {
        return (float) Transaction::where('branch_id', $branchId)
            ->whereIn('status', $statuses)
            ->whereMonth('created_at', $month->month)
            ->whereYear('created_at', $month->year)
            ->sum('total_amount');
    }

    public function findWithDetails(int $branchId, string $uuid): ?Transaction
    {
        return Transaction::where('branch_id', $branchId)
            ->where('uuid', $uuid)
            ->with(['staff.user', 'items.menuItem', 'items.addons', 'payments'])
            ->first();
    }

    public function findForRefund(int $branchId, string $uuid): ?Transaction
    {
        return Transaction::where('branch_id', $branchId)
            ->where('uuid', $uuid)
            ->with(['items', 'payments'])
            ->first();
    }

    // ── Refunds (call inside a transaction) ──────────────────────────────

    public function markRefunded(Transaction $transaction, string $note, array $event): void
    {
        $transaction->update([
            'status'       => 'refunded',
            'walk_in_note' => $note,
        ]);

        $transaction->events()->create($event);
        $transaction->payments()->update(['status' => 'refunded']);
    }
}
