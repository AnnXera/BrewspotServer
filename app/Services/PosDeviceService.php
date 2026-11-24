<?php

namespace App\Services;

use App\Http\Resources\PosDeviceResource;
use App\Models\CafeBranch;
use App\Models\CafeStaff;
use App\Models\PosDevice;
use App\Models\User;
use App\Models\MenuItem;
use App\Models\Transaction;
use App\Models\TransactionEvent;
use App\Models\TransactionItem;
use App\Repository\InventoryServingRepository;
use App\Repository\PosDeviceRepository;
use App\Repository\PosSaleRepository;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
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
    /** Sale statuses that count toward totals (net of refunds). Anything else (voided, refunded) is reported separately. */
    private const SETTLED_STATUSES = Transaction::SETTLED;

    /** Discounts the register may apply, and their rate on the order subtotal. */
    private const DISCOUNT_TYPES = ['PWD', 'Senior'];
    private const DISCOUNT_RATE = 0.20;

    public function __construct(
        private readonly PosDeviceRepository $repo,
        private readonly StaffPinService $pins,
        private readonly InventoryServingRepository $servings,
        private readonly BranchAlertService $alerts,
        private readonly PosSaleRepository $sales,
        private readonly ActivityLogService $activity,
        private readonly RegisterApprovalService $approvals
    ) {
    }

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

        $this->activity->posDevice($user, $device, \App\Models\ActivityLog::POS_DEVICE_REGISTERED,
            "Set up register \"{$device->name}\" on a device");

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

        $this->activity->posDevice($actor, $device, \App\Models\ActivityLog::POS_DEVICE_REMOVED,
            "Removed register \"{$device->name}\"");

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
            return $this->pinFailure($check, $device, $assignment->user);
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
            return $this->pinFailure($result, $device, $assignment->user);
        }

        return $this->unlockAs($device, $assignment->fresh('user.role'));
    }

    private function pinFailure(array $check, PosDevice $device, User $user): array
    {
        if (!empty($check['just_locked'])) {
            $this->alerts->pinLocked($device->branch, $user);
        }

        unset($check['just_locked']);

        return [
            ...$check,
            'http' => !empty($check['locked']) ? 423 : 422,
        ];
    }

    private function unlockAs(PosDevice $device, CafeStaff $assignment): array
    {
        // Someone else still signed in here is signed out by this unlock.
        $previous = $device->activeStaff;
        if ($previous && $previous->staff_id !== $assignment->staff_id) {
            $this->activity->posLock($device, $previous, 'switched');
        }

        $this->repo->setActiveStaff($device, $assignment);
        $this->activity->posUnlock($device, $assignment);

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
        $previous = $device->activeStaff;

        $this->repo->setActiveStaff($device, null);

        if ($previous) {
            $this->activity->posLock($device, $previous);
        }

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
     * @return Collection<int, int>
     */
    private function todaysStock(PosDevice $device): Collection
    {
        $servings = $this->servings->listForDate($device->branch_id, Carbon::today())->keyBy('men_item_id');

        return $this->servings->listAvailableItemsForBranch($device->branch)
            ->mapWithKeys(function ($item) use ($servings) {
                $s = $servings->get($item->men_item_id);

                return [
                    $item->men_item_id => ($s && !$s->is_sold_out)
                        ? $s->remaining()
                        : 0
                ];
            });
    }

    public function menu(PosDevice $device): array
    {
        $cafeId = $device->branch->cafe_id;

        $categories = $this->sales->categoriesForCafe($cafeId)
            ->map(fn($c) => [
                'uuid' => $c->uuid,
                'name' => $c->name,
            ]);

        // Same rules as the servings page: only items visible at this device's
        // branch, each with today's remaining stock. 0 stock = sold out.
        $stock = $this->todaysStock($device);

        $items = $this->servings->listAvailableItemsForBranch($device->branch)
            ->load(['sizes', 'addons' => fn($q) => $q->where('is_available', true)->orderBy('name')])
            ->map(fn($i) => [
                'uuid' => $i->uuid,
                'category_uuid' => $i->category->uuid ?? null,
                'menu_name' => $i->menu_name,
                'description' => $i->description,
                'base_price' => (float) $i->base_price,
                // Hot or iced is picked at the register; base_price / size price is the hot price.
                'is_hot_and_iced' => (bool) $i->is_hot_and_iced,
                'iced_price' => $i->iced_price !== null ? (float) $i->iced_price : null,
                'picture' => $this->publicDiskUrl($i->picture),
                'available_stock' => $stock->get($i->men_item_id, 0),
                'sold_out' => $stock->get($i->men_item_id, 0) <= 0,
                // Empty = sold at base_price. All sizes share the item's servings.
                'sizes' => $i->sizes->map(fn($s) => [
                    'uuid' => $s->uuid,
                    'name' => $s->name,
                    'price' => (float) $s->price,
                    'iced_price' => $s->iced_price !== null ? (float) $s->iced_price : null,
                ])->values(),
                'addons' => $i->addons->map(fn($a) => [
                    'uuid' => $a->uuid,
                    'name' => $a->name,
                    'price' => (float) $a->price,
                    'max_quantity' => $a->max_quantity, // null = no limit of its own
                    'quantity_cap' => $a->quantityCap(), // what the register allows per drink
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
     * sizes), at its iced price when served iced, plus each add-on x its
     * quantity. Add-ons must be offered on the item, available, and within
     * their per-drink limit. Temperature changes only the price, never the
     * ingredients used.
     *
     * @param array<int, array{uuid: string, quantity: int}> $addons
     * @return array{unit_price: float, size: ?\App\Models\MenuItemSize, temperature: ?string, addons: array}|array{message: string}
     */
    private function priceLine(MenuItem $menuItem, ?string $sizeUuid, ?string $temperature, array $addons): array
    {
        if ($menuItem->is_hot_and_iced && !$temperature) {
            return ['message' => "Pick hot or iced for {$menuItem->menu_name}."];
        }

        if (!$menuItem->is_hot_and_iced && $temperature) {
            return ['message' => "{$menuItem->menu_name} is not sold hot and iced. Remove it and add it again."];
        }

        $size = null;

        if ($menuItem->sizes->isNotEmpty()) {
            $size = $menuItem->sizes->firstWhere('uuid', $sizeUuid);

            if (!$size) {
                return ['message' => "Pick a size for {$menuItem->menu_name}."];
            }
        } elseif ($sizeUuid) {
            return ['message' => "{$menuItem->menu_name} does not come in sizes. Remove it and add it again."];
        }

        $unitPrice = $temperature === 'iced'
            ? ($size ? $size->iced_price : $menuItem->iced_price)
            : ($size?->price ?? $menuItem->base_price);

        if ($unitPrice === null) {
            return ['message' => "{$menuItem->menu_name} has no iced price yet. Ask the owner to set it."];
        }

        $unitPrice = (float) $unitPrice;
        $lines = [];

        foreach ($addons as $row) {
            $addon = $menuItem->addons->firstWhere('uuid', $row['uuid']);

            if (!$addon) {
                return ['message' => "An add-on on {$menuItem->menu_name} is no longer available. Remove it and add it again."];
            }

            if (isset($lines[$addon->addon_id])) {
                return ['message' => "{$addon->name} is listed twice on {$menuItem->menu_name}."];
            }

            $quantity = (int) $row['quantity'];

            if ($quantity > $addon->quantityCap()) {
                return ['message' => "{$menuItem->menu_name} can have at most {$addon->quantityCap()} {$addon->name}."];
            }

            $lines[$addon->addon_id] = ['addon' => $addon, 'quantity' => $quantity];
            $unitPrice += (float) $addon->price * $quantity;
        }

        return ['unit_price' => round($unitPrice, 2), 'size' => $size, 'temperature' => $temperature, 'addons' => array_values($lines)];
    }

    public function checkout(PosDevice $device, array $items, string $paymentMethod, float $amountTendered, ?string $referenceNumber = null, ?string $discountType = null, float $discountAmount = 0, ?string $orderType = 'dine_in', ?string $discountIdNumber = null, ?string $discountIdImage = null): array
    {
        if (!$device->active_staff_id) {
            return ['success' => false, 'http' => 403, 'message' => 'Register is locked.'];
        }

        $orderType = in_array($orderType, ['dine_in', 'take_out', 'delivery'], true) ? $orderType : 'dine_in';

        $staff = $this->repo->findStaff($device->active_staff_id);
        $userId = $staff ? $staff->user_id : $device->registered_by;

        $branchId = $device->branch_id;
        $cafeId = $device->branch->cafe_id;

        // Process and store verification ID image if provided (e.g. for PWD / Senior Citizen)
        $savedImagePath = null;
        if (!empty($discountIdImage)) {
            if (preg_match('/^data:image\/(\w+);base64,/', $discountIdImage, $matches)) {
                $rawImage = base64_decode(substr($discountIdImage, strpos($discountIdImage, ',') + 1));
                $ext = strtolower($matches[1]);
                if (in_array($ext, ['jpg', 'jpeg', 'png', 'webp', 'gif'], true) && $rawImage !== false) {
                    $fileName = 'discount_ids/' . Str::uuid() . '.' . $ext;
                    Storage::disk('public')->put($fileName, $rawImage);
                    $savedImagePath = $fileName;
                }
            } elseif (filter_var($discountIdImage, FILTER_VALIDATE_URL) || str_starts_with($discountIdImage, 'discount_ids/')) {
                $savedImagePath = $discountIdImage;
            }
        }

        $totalAmount = 0;
        $transactionItemsData = [];

        // Only what this branch offers, within today's remaining servings.
        $stock = $this->todaysStock($device);
        $requested = [];

        foreach ($items as $itemData) {
            $menuItem = $this->sales->findMenuItemForSale($itemData['uuid'], $cafeId);
            if (!$menuItem || !$stock->has($menuItem->men_item_id)) {
                return ['success' => false, 'http' => 400, 'message' => 'Invalid menu item in cart.'];
            }
            $quantity = (int) $itemData['quantity'];

            // Every size draws from the item's one servings count.
            $requested[$menuItem->men_item_id] = ($requested[$menuItem->men_item_id] ?? 0) + $quantity;
            if ($requested[$menuItem->men_item_id] > $stock->get($menuItem->men_item_id)) {
                return ['success' => false, 'http' => 422, 'message' => "{$menuItem->menu_name} is sold out or has too few servings left."];
            }

            // Prices always come from the menu, never from the register.
            $line = $this->priceLine($menuItem, $itemData['size_uuid'] ?? null, $itemData['temperature'] ?? null, $itemData['addons'] ?? []);
            if (isset($line['message'])) {
                return ['success' => false, 'http' => 422, 'message' => $line['message']];
            }

            $totalAmount += $line['unit_price'] * $quantity;

            $transactionItemsData[] = [
                'row' => [
                    'men_item_id' => $menuItem->men_item_id,
                    'men_size_id' => $line['size']?->men_size_id,
                    'size_name' => $line['size']?->name,
                    'temperature' => $line['temperature'],
                    'quantity' => $quantity,
                    'unit_price' => $line['unit_price'],
                ],
                'addons' => $line['addons'],
            ];
        }

        // PWD / Senior: 20% of the order, worked out here from the server's own prices.
        // The amount the register sends is ignored so it can't over- or under-discount.
        $discountType = in_array($discountType, self::DISCOUNT_TYPES, true) ? $discountType : null;
        $discountAmount = $discountType ? round($totalAmount * self::DISCOUNT_RATE, 2) : 0.0;

        // No VAT: the POS is not BIR-accredited, so prices are charged as-is.
        $grandTotal = $totalAmount - $discountAmount;

        if ($paymentMethod === 'cash' && $amountTendered < $grandTotal) {
            return ['success' => false, 'http' => 400, 'message' => 'Amount tendered is less than total amount.'];
        }

        $transaction = null;
        $servingChanges = []; // [serving, used before this sale], alerted on after commit
        DB::transaction(function () use (&$transaction, &$servingChanges, $device, $branchId, $grandTotal, $transactionItemsData, $paymentMethod, $amountTendered, $userId, $referenceNumber, $discountType, $discountAmount, $orderType, $discountIdNumber, $savedImagePath) {
            $transaction = $this->sales->createTransaction([
                'branch_id' => $branchId,
                'staff_id' => $device->active_staff_id,
                'total_amount' => $grandTotal,
                'discount_type' => $discountType,
                'discount_amount' => $discountAmount,
                'discount_id_number' => $discountIdNumber,
                'discount_id_image' => $savedImagePath,
                'status' => 'completed',
                'order_type' => $orderType,
                'receipt_number' => $this->sales->nextReceiptNumber($branchId),
            ]);

            foreach ($transactionItemsData as ['row' => $itemData, 'addons' => $addonLines]) {
                $txItem = $this->sales->createItem($transaction, $itemData);

                // 1. Today's serving counter
                $serving = $this->sales->lockOrCreateServing($itemData['men_item_id'], $branchId, Carbon::today());
                $servingChanges[] = [$serving, $serving->used()];
                $this->sales->recordServingsSold($serving, $itemData['quantity']);

                // 2. Ingredient usage: rows for every size, plus the sold size's own rows
                foreach ($this->sales->recipesForSoldItem($itemData['men_item_id'], $itemData['men_size_id']) as $recipe) {
                    $this->sales->logConsumption($txItem, $recipe, $itemData['quantity']);
                }

                // 3. Add-ons, and what they use (per drink x drinks on the line)
                foreach ($addonLines as ['addon' => $addon, 'quantity' => $addonQty]) {
                    $this->sales->createItemAddon($txItem, [
                        'addon_id' => $addon->addon_id,
                        'addon_name' => $addon->name,
                        'price' => $addon->price,
                        'quantity' => $addonQty,
                    ]);

                    foreach ($addon->recipes as $recipe) {
                        $this->sales->logConsumption($txItem, $recipe, $addonQty * $itemData['quantity']);
                    }
                }
            }

            $this->sales->recordPayment($transaction, [
                'user_id' => $userId,
                'payment_method_type' => $paymentMethod,
                'payment_instrument' => $referenceNumber,
                'amount' => $grandTotal,
                'amount_tendered' => $amountTendered,
                'amount_change' => $amountTendered - $grandTotal,
                'status' => 'paid',
            ]);
        });

        foreach ($servingChanges as [$serving, $usedBefore]) {
            $this->alerts->servingsUsed($serving, $usedBefore);
        }

        return [
            'success' => true,
            'message' => 'Checkout successful.',
            'transaction_uuid' => $transaction->uuid,
            'receipt_number' => $transaction->receipt_number,
            'order_type' => $transaction->order_type ?: 'dine_in',
        ];
    }

    /**
     * Today's sales for this register's branch. Totals count settled sales only, so voided
     * sales never inflate what the drawer should hold; voids are reported beside them.
     */
    public function transactions(PosDevice $device): array
    {
        $branchId = $device->branch_id;
        $settled = self::SETTLED_STATUSES;

        $todayTransactions = $this->sales->transactionsOn($branchId, Carbon::today());

        [$todaySettled, $todayVoided] = $todayTransactions->partition(fn($t) => in_array($t->status, $settled, true));

        $monthTotal = $this->sales->monthTotal($branchId, Carbon::now(), $settled);

        // Partly refunded sales count what they kept; what they gave back sits with the voids and refunds.
        $netTotal = round((float) $todaySettled->sum(fn ($t) => $t->netAmount()), 2);
        $voidTotal = round((float) $todayVoided->sum('total_amount') + (float) $todaySettled->sum('refunded_amount'), 2);

        return [
            'success' => true,
            'today_total' => $netTotal,                // net: settled sales only
            'today_count' => $todaySettled->count(),
            'gross_total' => $netTotal + $voidTotal,   // everything rung up, voids included
            'void_count' => $todayVoided->count(),
            'void_total' => $voidTotal,
            'month_total' => (float) $monthTotal,      // net, settled sales only
            'transactions' => $todayTransactions->map(fn($t) => [
                'uuid' => $t->uuid,
                'receipt_number' => $t->receipt_number ?: str_pad((string) $t->transaction_id, 6, '0', STR_PAD_LEFT),
                'created_at' => $t->created_at->toISOString(),
                'staff_name' => $t->staff ? $t->staff->user->firstname . ' ' . $t->staff->user->lastname : 'Admin',
                'total_amount' => (float) $t->total_amount,
                'refunded_amount' => (float) $t->refunded_amount,
                'status' => $t->status,
                'order_type' => $t->order_type ?: 'dine_in',
                'items_count' => $t->items_count,
            ])->values(),
        ];
    }

    /**
     * One sale from this register's branch: lines with size and add-ons, discount, and payment.
     */
    public function transaction(PosDevice $device, string $transactionUuid): array
    {
        $t = $this->sales->findWithDetails($device->branch_id, $transactionUuid);

        if (!$t) {
            return ['success' => false, 'http' => 404, 'message' => 'Transaction not found.'];
        }

        $payment = $t->payments ? $t->payments->sortByDesc('created_at')->first() : null;
        $items = $t->items ?? collect();
        $subtotal = $items->sum(fn($i) => (float) $i->unit_price * $i->quantity);

        $branch = $device->branch;
        $cafe = $branch?->cafe;

        return [
            'success' => true,
            'transaction' => [
                'uuid' => $t->uuid,
                'receipt_number' => $t->receipt_number ?: str_pad((string) $t->transaction_id, 6, '0', STR_PAD_LEFT),
                'cafe_name' => $cafe?->cafe_name,
                'branch_name' => $branch?->branch_name,
                'branch_address' => $branch?->address,
                'created_at' => $t->created_at->toISOString(),
                'staff_name' => $t->staff ? $t->staff->user->firstname . ' ' . $t->staff->user->lastname : 'Admin',
                'status' => $t->status,
                'order_type' => $t->order_type ?: 'dine_in',
                'walk_in_note' => $t->walk_in_note,
                'subtotal' => round($subtotal, 2),
                'discount_type' => $t->discount_type,
                'discount_amount' => (float) $t->discount_amount,
                'discount_id_number' => $t->discount_id_number,
                'discount_id_image' => $this->publicDiskUrl($t->discount_id_image),
                'total_amount' => (float) $t->total_amount,
                'refunded_amount' => (float) $t->refunded_amount,
                'payment' => $payment ? [
                    'method' => $payment->payment_method_type,
                    'reference_number' => $payment->payment_instrument,
                    'amount_tendered' => (float) $payment->amount_tendered,
                    'change' => (float) $payment->amount_change,
                ] : null,
                'items' => $items->map(fn($i) => [
                    'uuid' => $i->uuid,
                    'menu_name' => $i->menuItem?->menu_name ?? ('Item #' . $i->men_item_id),
                    'size_name' => $i->size_name,
                    'temperature' => $i->temperature,
                    'quantity' => $i->quantity,
                    'refunded_quantity' => (int) $i->refunded_quantity,
                    'unit_price' => (float) $i->unit_price, // size price plus add-ons, per drink
                    'line_total' => round((float) $i->unit_price * $i->quantity, 2),
                    'addons' => ($i->addons ?? collect())->map(fn($a) => [
                        'name' => $a->addon_name,
                        'quantity' => (int) $a->quantity,
                        'price' => (float) $a->price,
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

        $staffManagers = $this->repo->findBranchManagers($branchId)
            ->map(fn($s) => [
                'uuid' => $s->user->uuid,
                'name' => $s->user->firstname . ' ' . $s->user->lastname,
                'role' => 'Manager',
                'has_pin' => $s->user->hasPin(),
                'is_locked' => $s->user->isPinLocked(),
            ]);

        $owner = $cafe?->owner;
        $ownerList = collect();
        if ($owner && $owner->status !== 'inactive') {
            $ownerList->push([
                'uuid' => $owner->uuid,
                'name' => $owner->firstname . ' ' . $owner->lastname . ' (Owner)',
                'role' => 'Cafe Owner',
                'has_pin' => $owner->hasPin(),
                'is_locked' => $owner->isPinLocked(),
            ]);
        }

        $all = $staffManagers->concat($ownerList)->unique('uuid')->values();

        return [
            'success' => true,
            'managers' => $all,
        ];
    }

    /**
     * Refund some or all of a sale's items, requiring manager PIN authorization.
     * $items: [{uuid: transaction item uuid, quantity}]; null refunds everything not refunded yet.
     * $restock: the order was never made, so its servings go back on sale. Otherwise
     * they were made and served, and count as refunded (still used up).
     *
     * Each item returns its line price less its share of the sale's discount. The refund
     * that empties the sale returns exactly what is left, so refunds add up to what was paid.
     *
     * @param  array<int, array{uuid: string, quantity: int}>|null  $items
     */
    public function refund(PosDevice $device, string $transactionUuid, string $managerUuid, string $pin, ?string $reason = null, bool $restock = false, ?array $items = null): array
    {
        if (!$device->active_staff_id) {
            return ['success' => false, 'http' => 403, 'message' => 'Register is locked. Please unlock the register first.'];
        }

        $transaction = $this->sales->findForRefund($device->branch_id, $transactionUuid);

        if (!$transaction) {
            return ['success' => false, 'http' => 404, 'message' => 'Transaction not found.'];
        }

        if (!in_array($transaction->status, self::SETTLED_STATUSES, true)) {
            return ['success' => false, 'http' => 422, 'message' => "This transaction has already been {$transaction->status}."];
        }

        // Checked before the PIN so a wrong pick doesn't cost a PIN attempt; checked again under lock.
        $planned = $this->refundPlan($transaction, $items);
        if (isset($planned['message'])) {
            return ['success' => false, 'http' => 422, 'message' => $planned['message']];
        }

        // An active manager at this branch or the cafe owner, with their PIN.
        $approval = $this->approvals->approve($device, $managerUuid, $pin, 'refunds');
        if (!$approval['success']) {
            return $approval;
        }
        $managerUser = $approval['user'];

        $result = DB::transaction(function () use ($transaction, $device, $managerUser, $reason, $restock, $items) {
            $locked = $this->sales->lockForRefund($transaction);
            if (!in_array($locked->status, self::SETTLED_STATUSES, true)) {
                return ['message' => "This transaction has already been {$locked->status}."];
            }

            $plan = $this->refundPlan($locked, $items);
            if (isset($plan['message'])) {
                return $plan; // changed since the check above (another refund got there first)
            }

            $actorRole = $managerUser->roleName() ?: 'Manager';
            $refundNote = ($plan['complete'] ? 'Refunded' : 'Partly refunded')
                . " by {$managerUser->firstname} {$managerUser->lastname} ({$actorRole})";
            if (!empty($reason)) {
                $refundNote .= ': ' . trim($reason);
            }
            if ($locked->walk_in_note) {
                $refundNote = $locked->walk_in_note . ' | ' . $refundNote;
            }

            // The event (who approved, why, which items), the lines' refunded counts,
            // and the sale's refunded amount and status.
            $this->sales->recordRefund($locked, $refundNote, $plan['complete'] ? 'refunded' : Transaction::PARTIALLY_REFUNDED, $plan['amount'], [
                'event' => TransactionEvent::REFUNDED,
                'user_id' => $managerUser->user_id,
                'reason' => !empty($reason) ? trim($reason) : null,
                'restocked' => $restock,
            ], $plan['lines']);

            // Take the refunded servings off the day's sold count. Made servings can't be
            // sold again, so they move to servings_refunded and what's left stays the same;
            // servings never made go back on sale.
            foreach ($plan['lines'] as ['item' => $item, 'quantity' => $refundQty]) {
                $serving = $this->sales->lockServing($item->men_item_id, $device->branch_id, $locked->created_at);

                $qty = $serving ? min($serving->servings_sold, $refundQty) : 0;
                if ($qty <= 0) {
                    continue;
                }

                $ranOut = $serving->isUsedUp();
                $serving->servings_sold -= $qty;

                if ($restock) {
                    // Sold out only because it ran out (not switched off by a manager): back on sale.
                    if ($serving->is_sold_out && $ranOut && !$serving->isUsedUp()) {
                        $serving->is_sold_out = false;
                    }
                } else {
                    $serving->servings_refunded += $qty;
                }

                $this->sales->saveServing($serving);
            }

            return $plan;
        });

        if (isset($result['message'])) {
            return ['success' => false, 'http' => 409, 'message' => $result['message']];
        }

        $this->alerts->refundApproved($device->branch, $transaction, $managerUser, $reason, $result['amount']);

        Log::channel('auth')->info('POS transaction refunded.', [
            'transaction_uuid' => $transaction->uuid,
            'receipt_number' => $transaction->receipt_number,
            'amount' => $result['amount'],
            'complete' => $result['complete'],
            'items' => collect($result['lines'])->map(fn ($l) => ['uuid' => $l['item']->uuid, 'quantity' => $l['quantity']])->all(),
            'manager_uuid' => $managerUser->uuid,
            'manager_name' => "{$managerUser->firstname} {$managerUser->lastname}",
            'reason' => $reason,
            'restocked' => $restock,
        ]);

        return [
            'success' => true,
            'message' => $result['complete'] ? 'Transaction successfully refunded.' : 'Items refunded.',
            'refunded_amount' => $result['amount'],
            'transaction' => $this->transaction($device, $transaction->uuid)['transaction'] ?? null,
        ];
    }

    /**
     * What a refund would return: the lines and quantities, the money for each, and
     * whether it empties the sale. A message instead when the pick is not valid.
     *
     * @param  array<int, array{uuid: string, quantity: int}>|null  $picked  null: everything left
     * @return array{lines: array<int, array{item: TransactionItem, quantity: int, amount: float}>, amount: float, complete: bool}|array{message: string}
     */
    private function refundPlan(Transaction $transaction, ?array $picked): array
    {
        $byUuid = $transaction->items->keyBy('uuid');
        $wanted = [];

        if ($picked === null) {
            foreach ($transaction->items as $item) {
                if ($item->refundableQuantity() > 0) {
                    $wanted[$item->uuid] = $item->refundableQuantity();
                }
            }
        } else {
            foreach ($picked as $row) {
                $uuid = (string) ($row['uuid'] ?? '');
                $qty = (int) ($row['quantity'] ?? 0);
                if (!$byUuid->has($uuid)) {
                    return ['message' => 'An item to refund is not on this sale.'];
                }
                if ($qty < 1) {
                    continue;
                }
                $wanted[$uuid] = ($wanted[$uuid] ?? 0) + $qty;
            }
        }

        if (!$wanted) {
            return ['message' => $picked === null ? 'Everything on this sale has already been refunded.' : 'Pick at least one item to refund.'];
        }

        $lines = [];
        foreach ($wanted as $uuid => $qty) {
            /** @var TransactionItem $item */
            $item = $byUuid->get($uuid);
            if ($qty > $item->refundableQuantity()) {
                $name = $item->menuItem?->menu_name ?? 'An item';
                return ['message' => $item->refundableQuantity() === 0
                    ? "{$name} has already been refunded."
                    : "Only {$item->refundableQuantity()} of {$name} can still be refunded."];
            }
            $lines[] = ['item' => $item, 'quantity' => $qty, 'amount' => 0.0];
        }

        // Share of the sale actually paid per peso of list price (1 without a discount).
        $subtotal = $transaction->items->sum(fn ($i) => (float) $i->unit_price * (int) $i->quantity);
        $paidRatio = $subtotal > 0 ? (float) $transaction->total_amount / $subtotal : 0.0;
        $remaining = round((float) $transaction->total_amount - (float) $transaction->refunded_amount, 2);

        $complete = $transaction->items->every(fn ($i) => $i->refundableQuantity() === (int) ($wanted[$i->uuid] ?? 0));

        foreach ($lines as $i => $line) {
            $lines[$i]['amount'] = round((float) $line['item']->unit_price * $line['quantity'] * $paidRatio, 2);
        }
        $amount = round(array_sum(array_column($lines, 'amount')), 2);

        // The last refund returns exactly what is left; never more than what is left.
        $target = $complete ? $remaining : min($amount, $remaining);
        if ($lines && abs($target - $amount) > 0.0001) {
            $last = array_key_last($lines);
            $lines[$last]['amount'] = round($lines[$last]['amount'] + ($target - $amount), 2);
        }

        return ['lines' => $lines, 'amount' => max(0, round($target, 2)), 'complete' => $complete];
    }

    /**
     * Void a transaction completely, requiring manager PIN authorization.
     * Cancels the sale and marks payments voided. Its servings go back on sale
     * (20 planned, 1 sold = 19 left; after the void 20 left again).
     */
    public function void(PosDevice $device, string $transactionUuid, string $managerUuid, string $pin, ?string $reason = null): array
    {
        if (!$device->active_staff_id) {
            return ['success' => false, 'http' => 403, 'message' => 'Register is locked. Please unlock the register first.'];
        }

        $transaction = $this->sales->findForRefund($device->branch_id, $transactionUuid);

        if (!$transaction) {
            return ['success' => false, 'http' => 404, 'message' => 'Transaction not found.'];
        }

        if ($transaction->status === Transaction::PARTIALLY_REFUNDED) {
            return ['success' => false, 'http' => 422, 'message' => 'Some items on this sale were already refunded. Refund the rest instead of voiding it.'];
        }

        if (in_array($transaction->status, ['refunded', 'voided'], true)) {
            return ['success' => false, 'http' => 422, 'message' => "This transaction has already been {$transaction->status}."];
        }

        $approval = $this->approvals->approve($device, $managerUuid, $pin, 'voids');
        if (!$approval['success']) {
            return $approval;
        }
        $managerUser = $approval['user'];

        DB::transaction(function () use ($transaction, $device, $managerUser, $reason) {
            $actorRole = $managerUser->roleName() ?: 'Manager';
            $voidNote = "Voided by {$managerUser->firstname} {$managerUser->lastname} ({$actorRole})";
            if (!empty($reason)) {
                $voidNote .= ': ' . trim($reason);
            }
            if ($transaction->walk_in_note) {
                $voidNote = $transaction->walk_in_note . ' | ' . $voidNote;
            }

            $this->sales->markVoided($transaction, $voidNote, [
                'event'     => TransactionEvent::VOIDED,
                'user_id'   => $managerUser->user_id,
                'reason'    => !empty($reason) ? trim($reason) : null,
                'restocked' => true,
            ]);

            // A void cancels the sale, so its servings go back on sale: off the sold
            // count, and the remaining count goes up by the same amount.
            foreach ($transaction->items as $item) {
                $serving = $this->sales->lockServing($item->men_item_id, $device->branch_id, $transaction->created_at);

                $qty = $serving ? min($serving->servings_sold, (int) $item->quantity) : 0;
                if ($qty <= 0) {
                    continue;
                }

                $ranOut = $serving->isUsedUp();
                $serving->servings_sold -= $qty;

                // Sold out only because it ran out (not switched off by a manager): back on sale.
                if ($serving->is_sold_out && $ranOut && !$serving->isUsedUp()) {
                    $serving->is_sold_out = false;
                }

                $this->sales->saveServing($serving);
            }
        });

        Log::channel('auth')->info('POS transaction voided.', [
            'transaction_uuid' => $transaction->uuid,
            'receipt_number'   => $transaction->receipt_number,
            'amount'           => $transaction->total_amount,
            'manager_uuid'     => $managerUser->uuid,
            'manager_name'     => "{$managerUser->firstname} {$managerUser->lastname}",
            'reason'           => $reason,
        ]);

        return [
            'success' => true,
            'message' => 'Transaction successfully voided.',
            'transaction' => $this->transaction($device, $transaction->uuid)['transaction'] ?? null,
        ];
    }

    /**
     * Resolves a public URL for a file stored on the public disk.
     */
    private function publicDiskUrl(?string $path): ?string
    {
        if (empty($path)) {
            return null;
        }

        /** @var \Illuminate\Filesystem\FilesystemAdapter $disk */
        $disk = Storage::disk('public');

        return $disk->url($path);
    }
}
