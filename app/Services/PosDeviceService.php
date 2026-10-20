<?php

namespace App\Services;

use App\Http\Resources\PosDeviceResource;
use App\Models\CafeBranch;
use App\Models\CafeStaff;
use App\Models\PosDevice;
use App\Models\User;
use App\Models\MenuCategory;
use App\Models\MenuItem;
use App\Models\Transaction;
use App\Models\TransactionEvent;
use App\Models\TransactionItem;
use App\Models\Payment;
use App\Http\Resources\MenuCategoryResource;
use App\Http\Resources\MenuItemResource;
use App\Repository\InventoryServingRepository;
use App\Repository\PosDeviceRepository;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * Register (POS) lifecycle:
 *
 *   1. Setup   — an owner/manager signs in on the device once and registers
 *                it to a branch. The device gets its own token; the person's
 *                setup login is discarded.
 *   2. Unlock  — each shift, staff tap their name and enter their PIN. Only
 *                works with a device token, so PINs are never exposed to the
 *                open internet.
 *   3. Revoke  — owner/manager removes the device from the dashboard, or the
 *                device unregisters itself.
 *
 * Results carry an `http` key that the controller turns into the status code.
 */
class PosDeviceService
{
    /** Sale statuses that count toward totals. Anything else (voided, refunded) is reported separately. */
    private const SETTLED_STATUSES = ['completed', 'paid'];

    public function __construct(
        private readonly PosDeviceRepository $repo,
        private readonly StaffPinService $pins,
        private readonly InventoryServingRepository $servings
    ) {}

    // ── Setup (owner / manager token) ────────────────────────────────────

    public function registerableBranches(User $user): array
    {
        return [
            'success' => true,
            'branches' => $this->repo->findRegisterableBranches($user)->map(fn(CafeBranch $b) => [
                'uuid' => $b->uuid,
                'branch_name' => $b->branch_name,
                'address' => $b->address,
            ])->values(),
        ];
    }

    public function register(User $user, string $branchUuid, string $name): array
    {
        $branch = $this->repo->findRegisterableBranches($user)->firstWhere('uuid', $branchUuid);

        if (!$branch) {
            return ['success' => false, 'http' => 403, 'message' => 'You can\'t set up a register for this branch.'];
        }

        $device = $this->repo->create($branch->branch_id, $name, $user->user_id);
        $token = $device->createToken('pos-device')->plainTextToken;

        // The setup login on this device is no longer needed — drop it so the
        // manager/owner isn't left signed in on a shared counter device.
        $setupToken = $user->currentAccessToken();

        if ($setupToken instanceof PersonalAccessToken) {
            $setupToken->delete();
        }

        Log::channel('auth')->info('POS device registered.', [
            'device_uuid' => $device->uuid,
            'branch_uuid' => $branch->uuid,
            'by_uuid' => $user->uuid,
            'by_role' => $user->roleName(),
        ]);

        return [
            'success' => true,
            'http' => 201,
            'message' => "Register \"{$name}\" is set up for {$branch->branch_name}.",
            'device_token' => $token,
            'token_type' => 'Bearer',
            'device' => new PosDeviceResource($device->load(['branch', 'registeredBy'])),
        ];
    }

    // ── Dashboard: manage a branch's registers ───────────────────────────

    public function listForBranch(CafeBranch $branch): array
    {
        return [
            'success' => true,
            'devices' => PosDeviceResource::collection($this->repo->listForBranch($branch->branch_id)),
        ];
    }

    public function revokeForBranch(User $actor, CafeBranch $branch, string $deviceUuid): array
    {
        $device = $this->repo->findForBranch($deviceUuid, $branch->branch_id);

        if (!$device) {
            return ['success' => false, 'http' => 404, 'message' => 'Register not found.'];
        }

        $this->repo->revoke($device);

        Log::channel('auth')->info('POS device removed from dashboard.', [
            'device_uuid' => $device->uuid,
            'branch_uuid' => $branch->uuid,
            'by_uuid' => $actor->uuid,
        ]);

        return ['success' => true, 'message' => "Register \"{$device->name}\" removed. It will need to be set up again."];
    }

    // ── On the device (device token) ─────────────────────────────────────

    public function current(PosDevice $device): array
    {
        return [
            'success' => true,
            'device' => new PosDeviceResource($device->load(['branch.cafe', 'registeredBy', 'activeStaff.user'])),
        ];
    }

    public function lockScreenStaff(PosDevice $device): array
    {
        return [
            'success' => true,
            'staff' => $this->repo->findUnlockableStaff($device->branch_id)->map(fn(CafeStaff $a) => [
                'uuid' => $a->user->uuid,
                'firstname' => $a->user->firstname,
                'lastname' => $a->user->lastname,
                'role' => $a->user->roleName(),
                'pin_set' => $a->user->hasPin(),
                'pin_locked' => $a->user->isPinLocked(),
                'pin_must_change' => $a->user->mustChangePin(),
            ])->values(),
        ];
    }

    public function unlock(PosDevice $device, string $userUuid, string $pin): array
    {
        $assignment = $this->repo->findUnlockableAssignment($userUuid, $device->branch_id);

        if (!$assignment) {
            return ['success' => false, 'http' => 404, 'message' => 'This person is not active at this branch.'];
        }

        $check = $this->pins->verify($assignment->user, $pin);

        if (!$check['success']) {
            return $this->pinFailure($check);
        }

        // Correct temporary PIN: the register must ask for a new one
        // (POST .../change-pin) before letting them in.
        if ($assignment->user->mustChangePin()) {
            return [
                'success' => false,
                'http' => 409,
                'must_change_pin' => true,
                'message' => 'This is a temporary PIN. Please choose a new PIN to continue.',
            ];
        }

        return $this->unlockAs($device, $assignment);
    }

    /**
     * Replace a temporary PIN on the register, then unlock.
     */
    public function changePinAndUnlock(PosDevice $device, string $userUuid, string $currentPin, string $newPin): array
    {
        $assignment = $this->repo->findUnlockableAssignment($userUuid, $device->branch_id);

        if (!$assignment) {
            return ['success' => false, 'http' => 404, 'message' => 'This person is not active at this branch.'];
        }

        $result = $this->pins->changeWithCurrentPin($assignment->user, $currentPin, $newPin);

        if (!$result['success']) {
            return $this->pinFailure($result);
        }

        return $this->unlockAs($device, $assignment->fresh('user.role'));
    }

    private function pinFailure(array $check): array
    {
        return [
            ...$check,
            'http' => !empty($check['locked']) ? 423 : 422,
        ];
    }

    private function unlockAs(PosDevice $device, CafeStaff $assignment): array
    {
        $this->repo->setActiveStaff($device, $assignment);

        Log::channel('auth')->info('POS unlocked.', [
            'device_uuid' => $device->uuid,
            'staff_uuid' => $assignment->user->uuid,
        ]);

        return [
            'success' => true,
            'message' => "Welcome, {$assignment->user->firstname}.",
            'staff' => [
                'uuid' => $assignment->user->uuid,
                'firstname' => $assignment->user->firstname,
                'lastname' => $assignment->user->lastname,
                'role' => $assignment->user->roleName(),
            ],
        ];
    }

    public function lock(PosDevice $device): array
    {
        $this->repo->setActiveStaff($device, null);

        return ['success' => true, 'message' => 'Register locked.'];
    }

    public function unregisterSelf(PosDevice $device): array
    {
        $this->repo->revoke($device);

        Log::channel('auth')->info('POS device unregistered itself.', ['device_uuid' => $device->uuid]);

        return ['success' => true, 'message' => 'This device is no longer a register.'];
    }

    /**
     * Remaining servings today for every item offered at the device's branch,
     * keyed by men_item_id. Items without a serving row, or switched off, are 0.
     *
     * @return \Illuminate\Support\Collection<int, int>
     */
    private function todaysStock(PosDevice $device): \Illuminate\Support\Collection
    {
        $servings = $this->servings->listForDate($device->branch_id, \Illuminate\Support\Carbon::today())->keyBy('men_item_id');

        return $this->servings->listAvailableItemsForBranch($device->branch)
            ->mapWithKeys(function ($item) use ($servings) {
                $s = $servings->get($item->men_item_id);

                return [$item->men_item_id => ($s && ! $s->is_sold_out)
                    ? $s->remaining()
                    : 0];
            });
    }

    public function menu(PosDevice $device): array
    {
        $cafeId = $device->branch->cafe_id;

        $categories = \App\Models\MenuCategory::where('cafe_id', $cafeId)
            ->orderBy('name')
            ->get()
            ->map(fn($c) => [
                'uuid' => $c->uuid,
                'name' => $c->name,
            ]);

        // Same rules as the servings page: only items visible at this device's
        // branch, each with today's remaining stock. 0 stock = sold out.
        $stock = $this->todaysStock($device);

        $items = $this->servings->listAvailableItemsForBranch($device->branch)
            ->load(['sizes', 'addons' => fn ($q) => $q->where('is_available', true)->orderBy('name')])
            ->map(fn ($i) => [
                'uuid'            => $i->uuid,
                'category_uuid'   => $i->category->uuid ?? null,
                'menu_name'       => $i->menu_name,
                'description'     => $i->description,
                'base_price'      => (float) $i->base_price,
                'picture'         => $i->picture ? \Illuminate\Support\Facades\Storage::disk('public')->url($i->picture) : null,
                'available_stock' => $stock->get($i->men_item_id, 0),
                'sold_out'        => $stock->get($i->men_item_id, 0) <= 0,
                // Empty = sold at base_price. All sizes share the item's servings.
                'sizes'           => $i->sizes->map(fn ($s) => [
                    'uuid'  => $s->uuid,
                    'name'  => $s->name,
                    'price' => (float) $s->price,
                ])->values(),
                'addons'          => $i->addons->map(fn ($a) => [
                    'uuid'         => $a->uuid,
                    'name'         => $a->name,
                    'price'        => (float) $a->price,
                    'max_quantity' => $a->max_quantity,
                ])->values(),
            ]);

        return [
            'success' => true,
            'categories' => $categories->values(),
            'items' => $items->values(),
        ];
    }

    /**
     * Price of one drink: the chosen size (or base price when the item has no
     * sizes) plus each add-on x its quantity. Add-ons must be offered on the
     * item, available, and within their per-drink limit.
     *
     * @param array<int, array{uuid: string, quantity: int}> $addons
     * @return array{unit_price: float, size: ?\App\Models\MenuItemSize, addons: array}|array{message: string}
     */
    private function priceLine(\App\Models\MenuItem $menuItem, ?string $sizeUuid, array $addons): array
    {
        $size = null;

        if ($menuItem->sizes->isNotEmpty()) {
            $size = $menuItem->sizes->firstWhere('uuid', $sizeUuid);

            if (! $size) {
                return ['message' => "Pick a size for {$menuItem->menu_name}."];
            }
        } elseif ($sizeUuid) {
            return ['message' => "{$menuItem->menu_name} does not come in sizes. Remove it and add it again."];
        }

        $unitPrice = (float) ($size?->price ?? $menuItem->base_price);
        $lines     = [];

        foreach ($addons as $row) {
            $addon = $menuItem->addons->firstWhere('uuid', $row['uuid']);

            if (! $addon) {
                return ['message' => "An add-on on {$menuItem->menu_name} is no longer available. Remove it and add it again."];
            }

            if (isset($lines[$addon->addon_id])) {
                return ['message' => "{$addon->name} is listed twice on {$menuItem->menu_name}."];
            }

            $quantity = (int) $row['quantity'];

            if ($quantity > $addon->max_quantity) {
                return ['message' => "{$menuItem->menu_name} can have at most {$addon->max_quantity} {$addon->name}."];
            }

            $lines[$addon->addon_id] = ['addon' => $addon, 'quantity' => $quantity];
            $unitPrice += (float) $addon->price * $quantity;
        }

        return ['unit_price' => round($unitPrice, 2), 'size' => $size, 'addons' => array_values($lines)];
    }

    public function checkout(PosDevice $device, array $items, string $paymentMethod, float $amountTendered, ?string $referenceNumber = null, ?string $discountType = null, float $discountAmount = 0): array
    {
        if (!$device->active_staff_id) {
            return ['success' => false, 'http' => 403, 'message' => 'Register is locked.'];
        }

        $staff = \App\Models\CafeStaff::find($device->active_staff_id);
        $userId = $staff ? $staff->user_id : $device->registered_by;

        $branchId = $device->branch_id;
        $cafeId = $device->branch->cafe_id;

        $totalAmount = 0;
        $transactionItemsData = [];

        // Only what this branch offers, within today's remaining servings.
        $stock     = $this->todaysStock($device);
        $requested = [];

        foreach ($items as $itemData) {
            $menuItem = \App\Models\MenuItem::where('uuid', $itemData['uuid'])
                ->where('cafe_id', $cafeId)
                ->with(['sizes', 'addons' => fn ($q) => $q->where('is_available', true)->with('recipes')])
                ->first();
            if (!$menuItem || !$stock->has($menuItem->men_item_id)) {
                return ['success' => false, 'http' => 400, 'message' => 'Invalid menu item in cart.'];
            }
            $quantity = (int)$itemData['quantity'];

            // Every size draws from the item's one servings count.
            $requested[$menuItem->men_item_id] = ($requested[$menuItem->men_item_id] ?? 0) + $quantity;
            if ($requested[$menuItem->men_item_id] > $stock->get($menuItem->men_item_id)) {
                return ['success' => false, 'http' => 422, 'message' => "{$menuItem->menu_name} is sold out or has too few servings left."];
            }

            // Prices always come from the menu, never from the register.
            $line = $this->priceLine($menuItem, $itemData['size_uuid'] ?? null, $itemData['addons'] ?? []);
            if (isset($line['message'])) {
                return ['success' => false, 'http' => 422, 'message' => $line['message']];
            }

            $totalAmount += $line['unit_price'] * $quantity;

            $transactionItemsData[] = [
                'row' => [
                    'men_item_id' => $menuItem->men_item_id,
                    'men_size_id' => $line['size']?->men_size_id,
                    'size_name'   => $line['size']?->name,
                    'quantity'    => $quantity,
                    'unit_price'  => $line['unit_price'],
                ],
                'addons' => $line['addons'],
            ];
        }

        // No VAT: the POS is not BIR-accredited, so prices are charged as-is.
        $grandTotal = $totalAmount - $discountAmount;

        if ($paymentMethod === 'cash' && $amountTendered < $grandTotal) {
            return ['success' => false, 'http' => 400, 'message' => 'Amount tendered is less than total amount.'];
        }

        $transaction = null;
        \Illuminate\Support\Facades\DB::transaction(function () use (&$transaction, $device, $branchId, $grandTotal, $transactionItemsData, $paymentMethod, $amountTendered, $userId, $referenceNumber, $discountType, $discountAmount) {
            $latestTransaction = \App\Models\Transaction::where('branch_id', $branchId)
                ->lockForUpdate()
                ->orderBy('transaction_id', 'desc')
                ->first();

            $nextReceiptNumber = $latestTransaction && $latestTransaction->receipt_number && is_numeric($latestTransaction->receipt_number)
                ? str_pad((int) $latestTransaction->receipt_number + 1, 6, '0', STR_PAD_LEFT)
                : str_pad((int) ($latestTransaction?->transaction_id ?? 0) + 1, 6, '0', STR_PAD_LEFT);

            $transaction = \App\Models\Transaction::create([
                'branch_id' => $branchId,
                'staff_id' => $device->active_staff_id,
                'total_amount' => $grandTotal,
                'discount_type' => $discountType,
                'discount_amount' => $discountAmount,
                'status' => 'completed',
                'receipt_number' => $nextReceiptNumber,
            ]);

            foreach ($transactionItemsData as ['row' => $itemData, 'addons' => $addonLines]) {
                $txItem = \App\Models\TransactionItem::create(array_merge($itemData, [
                    'transaction_id' => $transaction->transaction_id,
                ]));

                // 1. Update Inventory Serving Tracker
                $serving = \App\Models\InventoryServing::firstOrCreate(
                    [
                        'men_item_id' => $itemData['men_item_id'],
                        'branch_id' => $branchId,
                        'date' => \Carbon\Carbon::today()->toDateString(),
                    ],
                    [
                        'expected_servings' => 0,
                        'servings_sold' => 0,
                        'spoilage_qty' => 0,
                        'servings_refunded' => 0,
                        'is_sold_out' => false,
                    ]
                );

                $serving->increment('servings_sold', $itemData['quantity']);

                if ($serving->isUsedUp()) {
                    $serving->update(['is_sold_out' => true]);
                }

                // 2. Ingredient Consumption Logs: rows for every size, plus the sold size's own rows
                $recipes = \App\Models\MenuRecipe::where('men_item_id', $itemData['men_item_id'])
                    ->where(fn ($q) => $q->whereNull('men_size_id')->orWhere('men_size_id', $itemData['men_size_id']))
                    ->get();
                foreach ($recipes as $recipe) {
                    \App\Models\IngredientConsumptionLog::create([
                        'transaction_item_id' => $txItem->transaction_item_id,
                        'ingredient_id' => $recipe->ingredient_id,
                        'ingredient_name' => $recipe->ingredient_name,
                        'quantity_consumed' => $recipe->quantity * $itemData['quantity'],
                        'unit' => $recipe->unit,
                    ]);
                }

                // 3. Add-ons, and what they use (per drink x drinks on the line)
                foreach ($addonLines as ['addon' => $addon, 'quantity' => $addonQty]) {
                    $txItem->addons()->create([
                        'addon_id'   => $addon->addon_id,
                        'addon_name' => $addon->name,
                        'price'      => $addon->price,
                        'quantity'   => $addonQty,
                    ]);

                    foreach ($addon->recipes as $recipe) {
                        \App\Models\IngredientConsumptionLog::create([
                            'transaction_item_id' => $txItem->transaction_item_id,
                            'ingredient_id'       => $recipe->ingredient_id,
                            'ingredient_name'     => $recipe->ingredient_name,
                            'quantity_consumed'   => $recipe->quantity * $addonQty * $itemData['quantity'],
                            'unit'                => $recipe->unit,
                        ]);
                    }
                }
            }

            // Record payment
            \App\Models\Payment::create([
                'user_id' => $userId,
                'payable_type' => \App\Models\Transaction::class,
                'payable_id' => $transaction->transaction_id,
                'payment_method_type' => $paymentMethod,
                'payment_instrument' => $referenceNumber,
                'amount' => $grandTotal,
                'amount_tendered' => $amountTendered,
                'amount_change' => $amountTendered - $grandTotal,
                'status' => 'paid',
            ]);
        });

        return [
            'success' => true,
            'message' => 'Checkout successful.',
            'transaction_uuid' => $transaction->uuid,
            'receipt_number' => $transaction->receipt_number,
        ];
    }

    /**
     * Today's sales for this register's branch. Totals count settled sales only, so voided
     * sales never inflate what the drawer should hold; voids are reported beside them.
     */
    public function transactions(PosDevice $device): array
    {
        $branchId = $device->branch_id;
        $settled  = self::SETTLED_STATUSES;

        $todayTransactions = \App\Models\Transaction::where('branch_id', $branchId)
            ->whereDate('created_at', \Carbon\Carbon::today())
            ->with(['staff.user'])
            ->withCount('items')
            ->orderBy('created_at')
            ->get();

        [$todaySettled, $todayVoided] = $todayTransactions->partition(fn ($t) => in_array($t->status, $settled, true));

        $monthTotal = \App\Models\Transaction::where('branch_id', $branchId)
            ->whereIn('status', $settled)
            ->whereMonth('created_at', \Carbon\Carbon::now()->month)
            ->whereYear('created_at', \Carbon\Carbon::now()->year)
            ->sum('total_amount');

        $netTotal  = (float) $todaySettled->sum('total_amount');
        $voidTotal = (float) $todayVoided->sum('total_amount');

        return [
            'success'      => true,
            'today_total'  => $netTotal,                // net: settled sales only
            'today_count'  => $todaySettled->count(),
            'gross_total'  => $netTotal + $voidTotal,   // everything rung up, voids included
            'void_count'   => $todayVoided->count(),
            'void_total'   => $voidTotal,
            'month_total'  => (float) $monthTotal,      // net, settled sales only
            'transactions' => $todayTransactions->map(fn ($t) => [
                'uuid'           => $t->uuid,
                'receipt_number' => $t->receipt_number ?: str_pad((string) $t->transaction_id, 6, '0', STR_PAD_LEFT),
                'created_at'     => $t->created_at->toISOString(),
                'staff_name'     => $t->staff ? $t->staff->user->firstname . ' ' . $t->staff->user->lastname : 'Admin',
                'total_amount'   => (float) $t->total_amount,
                'status'         => $t->status,
                'items_count'    => $t->items_count,
            ])->values(),
        ];
    }

    /**
     * One sale from this register's branch: lines with size and add-ons, discount, and payment.
     */
    public function transaction(PosDevice $device, string $transactionUuid): array
    {
        $t = \App\Models\Transaction::where('branch_id', $device->branch_id)
            ->where('uuid', $transactionUuid)
            ->with(['staff.user', 'items.menuItem', 'items.addons', 'payments'])
            ->first();

        if (! $t) {
            return ['success' => false, 'http' => 404, 'message' => 'Transaction not found.'];
        }

        $payment = $t->payments ? $t->payments->sortByDesc('created_at')->first() : null;
        $items = $t->items ?? collect();
        $subtotal = $items->sum(fn ($i) => (float) $i->unit_price * $i->quantity);

        return [
            'success'     => true,
            'transaction' => [
                'uuid'            => $t->uuid,
                'receipt_number'  => $t->receipt_number ?: str_pad((string) $t->transaction_id, 6, '0', STR_PAD_LEFT),
                'created_at'      => $t->created_at->toISOString(),
                'staff_name'      => $t->staff ? $t->staff->user->firstname . ' ' . $t->staff->user->lastname : 'Admin',
                'status'          => $t->status,
                'walk_in_note'    => $t->walk_in_note,
                'subtotal'        => round($subtotal, 2),
                'discount_type'   => $t->discount_type,
                'discount_amount' => (float) $t->discount_amount,
                'total_amount'    => (float) $t->total_amount,
                'payment'         => $payment ? [
                    'method'           => $payment->payment_method_type,
                    'reference_number' => $payment->payment_instrument,
                    'amount_tendered'  => (float) $payment->amount_tendered,
                    'change'           => (float) $payment->amount_change,
                ] : null,
                'items' => $items->map(fn ($i) => [
                    'uuid'       => $i->uuid,
                    'menu_name'  => $i->menuItem?->menu_name ?? ('Item #' . $i->men_item_id),
                    'size_name'  => $i->size_name,
                    'quantity'   => $i->quantity,
                    'unit_price' => (float) $i->unit_price, // size price plus add-ons, per drink
                    'line_total' => round((float) $i->unit_price * $i->quantity, 2),
                    'addons'     => ($i->addons ?? collect())->map(fn ($a) => [
                        'name'     => $a->addon_name,
                        'quantity' => (int) $a->quantity,
                        'price'    => (float) $a->price,
                    ])->values(),
                ])->values(),
            ],
        ];
    }

    /**
     * List managers and owner who have authority to approve refunds and voids.
     */
    public function approvingManagers(PosDevice $device): array
    {
        $branchId = $device->branch_id;
        $cafe = $device->branch?->cafe;

        $staffManagers = CafeStaff::where('branch_id', $branchId)
            ->active()
            ->whereHas('user', fn ($q) => $q
                ->where('status', '!=', 'inactive')
                ->whereHas('role', fn ($r) => $r->where('role_name', 'Manager')))
            ->with(['user.role'])
            ->get()
            ->map(fn ($s) => [
                'uuid'      => $s->user->uuid,
                'name'      => $s->user->firstname . ' ' . $s->user->lastname,
                'role'      => 'Manager',
                'has_pin'   => $s->user->hasPin(),
                'is_locked' => $s->user->isPinLocked(),
            ]);

        $owner = $cafe?->owner;
        $ownerList = collect();
        if ($owner && $owner->status !== 'inactive') {
            $ownerList->push([
                'uuid'      => $owner->uuid,
                'name'      => $owner->firstname . ' ' . $owner->lastname . ' (Owner)',
                'role'      => 'Cafe Owner',
                'has_pin'   => $owner->hasPin(),
                'is_locked' => $owner->isPinLocked(),
            ]);
        }

        $all = $staffManagers->concat($ownerList)->unique('uuid')->values();

        return [
            'success'  => true,
            'managers' => $all,
        ];
    }

    /**
     * Process a refund for a completed transaction, requiring manager PIN authorization.
     * $restock: the order was never made, so its servings go back on sale. Otherwise
     * they were made and served, and count as refunded (still used up).
     */
    public function refund(PosDevice $device, string $transactionUuid, string $managerUuid, string $pin, ?string $reason = null, bool $restock = false): array
    {
        if (! $device->active_staff_id) {
            return ['success' => false, 'http' => 403, 'message' => 'Register is locked. Please unlock the register first.'];
        }

        $transaction = Transaction::where('branch_id', $device->branch_id)
            ->where('uuid', $transactionUuid)
            ->with(['items', 'payments'])
            ->first();

        if (! $transaction) {
            return ['success' => false, 'http' => 404, 'message' => 'Transaction not found.'];
        }

        if (in_array($transaction->status, ['refunded', 'voided'], true)) {
            return ['success' => false, 'http' => 422, 'message' => "This transaction has already been {$transaction->status}."];
        }

        $managerUser = User::where('uuid', $managerUuid)
            ->where('status', '!=', 'inactive')
            ->with('role')
            ->first();

        if (! $managerUser) {
            return ['success' => false, 'http' => 404, 'message' => 'Manager account not found.'];
        }

        // Authorizing user must be an active manager at this branch or the cafe owner
        $isBranchManager = CafeStaff::where('branch_id', $device->branch_id)
            ->where('user_id', $managerUser->user_id)
            ->active()
            ->whereHas('user.role', fn ($r) => $r->where('role_name', 'Manager'))
            ->exists();

        $isCafeOwner = ($device->branch?->cafe?->owner_id === $managerUser->user_id) || $managerUser->isOwner();

        if (! $isBranchManager && ! $isCafeOwner) {
            return ['success' => false, 'http' => 403, 'message' => 'Only an authorized Manager or Cafe Owner can approve refunds.'];
        }

        if ($managerUser->mustChangePin()) {
            return ['success' => false, 'http' => 409, 'message' => 'Manager must change their temporary PIN before approving refunds.'];
        }

        $check = $this->pins->verify($managerUser, $pin);
        if (! $check['success']) {
            return [
                'success'       => false,
                'http'          => ! empty($check['locked']) ? 423 : 422,
                'message'       => $check['message'] ?? 'Incorrect manager PIN.',
                'attempts_left' => $check['attempts_left'] ?? null,
            ];
        }

        DB::transaction(function () use ($transaction, $device, $managerUser, $reason, $restock) {
            $actorRole = $managerUser->roleName() ?: 'Manager';
            $refundNote = "Refunded by {$managerUser->firstname} {$managerUser->lastname} ({$actorRole})";
            if (! empty($reason)) {
                $refundNote .= ': ' . trim($reason);
            }
            if ($transaction->walk_in_note) {
                $refundNote = $transaction->walk_in_note . ' | ' . $refundNote;
            }

            $transaction->update([
                'status'       => 'refunded',
                'walk_in_note' => $refundNote,
            ]);

            $transaction->events()->create([
                'event'     => TransactionEvent::REFUNDED,
                'user_id'   => $managerUser->user_id,
                'reason'    => ! empty($reason) ? trim($reason) : null,
                'restocked' => $restock,
            ]);

            // Update associated payments to refunded
            $transaction->payments()->update(['status' => 'refunded']);

            // Take the servings off the day's sold count. Made servings can't be sold
            // again, so they move to servings_refunded and what's left stays the same;
            // servings never made go back on sale.
            foreach ($transaction->items as $item) {
                $serving = \App\Models\InventoryServing::where('men_item_id', $item->men_item_id)
                    ->where('branch_id', $device->branch_id)
                    ->where('date', $transaction->created_at->toDateString())
                    ->lockForUpdate()
                    ->first();

                $qty = $serving ? min($serving->servings_sold, (int) $item->quantity) : 0;
                if ($qty <= 0) {
                    continue;
                }

                $ranOut = $serving->isUsedUp();
                $serving->servings_sold -= $qty;

                if ($restock) {
                    // Sold out only because it ran out (not switched off by a manager): back on sale.
                    if ($serving->is_sold_out && $ranOut && ! $serving->isUsedUp()) {
                        $serving->is_sold_out = false;
                    }
                } else {
                    $serving->servings_refunded += $qty;
                }

                $serving->save();
            }
        });

        Log::channel('auth')->info('POS transaction refunded.', [
            'transaction_uuid' => $transaction->uuid,
            'receipt_number'   => $transaction->receipt_number,
            'amount'           => $transaction->total_amount,
            'manager_uuid'     => $managerUser->uuid,
            'manager_name'     => "{$managerUser->firstname} {$managerUser->lastname}",
            'reason'           => $reason,
            'restocked'        => $restock,
        ]);

        return [
            'success'     => true,
            'message'     => 'Transaction successfully refunded.',
            'transaction' => $this->transaction($device, $transaction->uuid)['transaction'] ?? null,
        ];
    }
}
