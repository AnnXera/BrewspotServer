<?php

namespace App\Services;

use App\Http\Resources\PosDeviceResource;
use App\Models\CafeBranch;
use App\Models\CafeStaff;
use App\Models\PosDevice;
use App\Models\User;
use App\Repository\PosDeviceRepository;
use Illuminate\Support\Facades\Log;
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
    public function __construct(
        private readonly PosDeviceRepository $repo,
        private readonly StaffPinService $pins
    ) {}

    // ── Setup (owner / manager token) ────────────────────────────────────

    public function registerableBranches(User $user): array
    {
        return [
            'success'  => true,
            'branches' => $this->repo->findRegisterableBranches($user)->map(fn (CafeBranch $b) => [
                'uuid'        => $b->uuid,
                'branch_name' => $b->branch_name,
                'address'     => $b->address,
            ])->values(),
        ];
    }

    public function register(User $user, string $branchUuid, string $name): array
    {
        $branch = $this->repo->findRegisterableBranches($user)->firstWhere('uuid', $branchUuid);

        if (! $branch) {
            return ['success' => false, 'http' => 403, 'message' => 'You can\'t set up a register for this branch.'];
        }

        $device = $this->repo->create($branch->branch_id, $name, $user->user_id);
        $token  = $device->createToken('pos-device')->plainTextToken;

        // The setup login on this device is no longer needed — drop it so the
        // manager/owner isn't left signed in on a shared counter device.
        $setupToken = $user->currentAccessToken();

        if ($setupToken instanceof PersonalAccessToken) {
            $setupToken->delete();
        }

        Log::channel('auth')->info('POS device registered.', [
            'device_uuid' => $device->uuid,
            'branch_uuid' => $branch->uuid,
            'by_uuid'     => $user->uuid,
            'by_role'     => $user->roleName(),
        ]);

        return [
            'success'      => true,
            'http'         => 201,
            'message'      => "Register \"{$name}\" is set up for {$branch->branch_name}.",
            'device_token' => $token,
            'token_type'   => 'Bearer',
            'device'       => new PosDeviceResource($device->load(['branch', 'registeredBy'])),
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

        if (! $device) {
            return ['success' => false, 'http' => 404, 'message' => 'Register not found.'];
        }

        $this->repo->revoke($device);

        Log::channel('auth')->info('POS device removed from dashboard.', [
            'device_uuid' => $device->uuid,
            'branch_uuid' => $branch->uuid,
            'by_uuid'     => $actor->uuid,
        ]);

        return ['success' => true, 'message' => "Register \"{$device->name}\" removed. It will need to be set up again."];
    }

    // ── On the device (device token) ─────────────────────────────────────

    public function current(PosDevice $device): array
    {
        return [
            'success' => true,
            'device'  => new PosDeviceResource($device->load(['branch', 'registeredBy', 'activeStaff.user'])),
        ];
    }

    public function lockScreenStaff(PosDevice $device): array
    {
        return [
            'success' => true,
            'staff'   => $this->repo->findUnlockableStaff($device->branch_id)->map(fn (CafeStaff $a) => [
                'uuid'       => $a->user->uuid,
                'firstname'  => $a->user->firstname,
                'lastname'   => $a->user->lastname,
                'role'       => $a->user->roleName(),
                'pin_set'    => $a->user->hasPin(),
                'pin_locked' => $a->user->isPinLocked(),
                'pin_must_change' => $a->user->mustChangePin(),
            ])->values(),
        ];
    }

    public function unlock(PosDevice $device, string $userUuid, string $pin): array
    {
        $assignment = $this->repo->findUnlockableAssignment($userUuid, $device->branch_id);

        if (! $assignment) {
            return ['success' => false, 'http' => 404, 'message' => 'This person is not active at this branch.'];
        }

        $check = $this->pins->verify($assignment->user, $pin);

        if (! $check['success']) {
            return $this->pinFailure($check);
        }

        // Correct temporary PIN: the register must ask for a new one
        // (POST .../change-pin) before letting them in.
        if ($assignment->user->mustChangePin()) {
            return [
                'success'         => false,
                'http'            => 409,
                'must_change_pin' => true,
                'message'         => 'This is a temporary PIN. Please choose a new PIN to continue.',
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

        if (! $assignment) {
            return ['success' => false, 'http' => 404, 'message' => 'This person is not active at this branch.'];
        }

        $result = $this->pins->changeWithCurrentPin($assignment->user, $currentPin, $newPin);

        if (! $result['success']) {
            return $this->pinFailure($result);
        }

        return $this->unlockAs($device, $assignment->fresh('user.role'));
    }

    private function pinFailure(array $check): array
    {
        return [
            ...$check,
            'http' => ! empty($check['locked']) ? 423 : 422,
        ];
    }

    private function unlockAs(PosDevice $device, CafeStaff $assignment): array
    {
        $this->repo->setActiveStaff($device, $assignment);

        Log::channel('auth')->info('POS unlocked.', [
            'device_uuid' => $device->uuid,
            'staff_uuid'  => $assignment->user->uuid,
        ]);

        return [
            'success' => true,
            'message' => "Welcome, {$assignment->user->firstname}.",
            'staff'   => [
                'uuid'      => $assignment->user->uuid,
                'firstname' => $assignment->user->firstname,
                'lastname'  => $assignment->user->lastname,
                'role'      => $assignment->user->roleName(),
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

    public function menu(PosDevice $device): array
    {
        $cafeId = $device->branch->cafe_id;

        $categories = \App\Models\MenuCategory::where('cafe_id', $cafeId)
            ->orderBy('name')
            ->get()
            ->map(fn ($c) => [
                'uuid' => $c->uuid,
                'name' => $c->name,
            ]);

        $items = \App\Models\MenuItem::with('category')
            ->where('cafe_id', $cafeId)
            ->where('is_available', true)
            ->orderBy('menu_name')
            ->get()
            ->map(fn ($i) => [
                'uuid'          => $i->uuid,
                'category_uuid' => $i->category->uuid ?? null,
                'menu_name'     => $i->menu_name,
                'description'   => $i->description,
                'base_price'    => (float) $i->base_price,
                'picture'       => $i->picture ? \Illuminate\Support\Facades\Storage::disk('public')->url($i->picture) : null,
            ]);

        return [
            'success'    => true,
            'categories' => $categories->values(),
            'items'      => $items->values(),
        ];
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

        foreach ($items as $itemData) {
            $menuItem = \App\Models\MenuItem::where('uuid', $itemData['uuid'])->where('cafe_id', $cafeId)->first();
            if (!$menuItem) {
                return ['success' => false, 'http' => 400, 'message' => 'Invalid menu item in cart.'];
            }
            
            $quantity = (int)$itemData['quantity'];
            $price = $menuItem->base_price;

            // Add 25 pesos for each addon, except ice
            if (isset($itemData['addons']) && is_array($itemData['addons'])) {
                foreach ($itemData['addons'] as $addon) {
                    if (stripos($addon, 'ice') === false) {
                        $price += 25;
                    }
                }
            }

            $totalAmount += $price * $quantity;
            
            $transactionItemsData[] = [
                'men_item_id' => $menuItem->men_item_id,
                'quantity'    => $quantity,
                'sugar_level' => isset($itemData['sugar_level']) ? (int)$itemData['sugar_level'] : null,
                'addons'      => isset($itemData['addons']) ? $itemData['addons'] : null,
                'unit_price'  => $price,
            ];
        }

        // Add Tax (VAT 12%) if branch is VAT-registered
        $vatDoc = \App\Models\BranchDocument::where('branch_id', $branchId)->whereNotNull('vat')->latest('branch_doc_id')->first();
        $tax = ($vatDoc && $vatDoc->vat === 'vat-registered') ? $totalAmount * 0.12 : 0;
        $grandTotal = $totalAmount + $tax - $discountAmount;

        if ($paymentMethod === 'cash' && $amountTendered < $grandTotal) {
            return ['success' => false, 'http' => 400, 'message' => 'Amount tendered is less than total amount.'];
        }

        $transaction = null;
        \Illuminate\Support\Facades\DB::transaction(function () use (&$transaction, $device, $branchId, $grandTotal, $transactionItemsData, $paymentMethod, $amountTendered, $userId, $referenceNumber, $discountType, $discountAmount) {
            $latestTransaction = \App\Models\Transaction::where('branch_id', $branchId)
                ->whereNotNull('receipt_number')
                ->lockForUpdate()
                ->orderBy('transaction_id', 'desc')
                ->first();

            $nextReceiptNumber = $latestTransaction 
                ? str_pad((int)$latestTransaction->receipt_number + 1, 6, '0', STR_PAD_LEFT) 
                : '000001';

            $transaction = \App\Models\Transaction::create([
                'branch_id'       => $branchId,
                'staff_id'        => $device->active_staff_id,
                'total_amount'    => $grandTotal,
                'discount_type'   => $discountType,
                'discount_amount' => $discountAmount,
                'status'          => 'completed',
                'receipt_number'  => $nextReceiptNumber,
            ]);

            foreach ($transactionItemsData as $itemData) {
                $txItem = \App\Models\TransactionItem::create(array_merge($itemData, [
                    'transaction_id' => $transaction->transaction_id,
                ]));

                // 1. Update Inventory Serving Tracker
                $serving = \App\Models\InventoryServing::firstOrCreate(
                    [
                        'men_item_id' => $itemData['men_item_id'], 
                        'branch_id'   => $branchId, 
                        'date'        => \Carbon\Carbon::today()->toDateString()
                    ],
                    [
                        'expected_servings' => 0, 
                        'servings_sold'     => 0, 
                        'spoilage_qty'      => 0, 
                        'is_sold_out'       => false
                    ]
                );
                
                $serving->increment('servings_sold', $itemData['quantity']);
                
                if ($serving->expected_servings > 0 && $serving->servings_sold >= $serving->expected_servings) {
                    $serving->update(['is_sold_out' => true]);
                }

                // 2. Ingredient Consumption Logs
                $recipes = \App\Models\MenuRecipe::where('men_item_id', $itemData['men_item_id'])->get();
                foreach ($recipes as $recipe) {
                    \App\Models\IngredientConsumptionLog::create([
                        'transaction_item_id' => $txItem->transaction_item_id,
                        'ingredient_id'       => $recipe->ingredient_id,
                        'ingredient_name'     => $recipe->ingredient_name,
                        'quantity_consumed'   => $recipe->quantity * $itemData['quantity'],
                        'unit'                => $recipe->unit,
                    ]);
                }
            }

            // Record payment
            \App\Models\Payment::create([
                'user_id'             => $userId,
                'payable_type'        => \App\Models\Transaction::class,
                'payable_id'          => $transaction->transaction_id,
                'payment_method_type' => $paymentMethod,
                'payment_instrument'  => $referenceNumber,
                'amount'              => $grandTotal,
                'amount_tendered'     => $amountTendered,
                'amount_change'       => $amountTendered - $grandTotal,
                'status'              => 'paid',
            ]);
        });

        return [
            'success' => true,
            'message' => 'Checkout successful.',
            'transaction_uuid' => $transaction->uuid,
            'receipt_number' => $transaction->receipt_number,
        ];
    }

    public function transactions(PosDevice $device): array
    {
        $branchId = $device->branch_id;
        
        $todayTransactions = \App\Models\Transaction::where('branch_id', $branchId)
            ->whereDate('created_at', \Carbon\Carbon::today())
            ->with(['staff.user'])
            ->get();

        $monthTransactions = \App\Models\Transaction::where('branch_id', $branchId)
            ->whereMonth('created_at', \Carbon\Carbon::now()->month)
            ->whereYear('created_at', \Carbon\Carbon::now()->year)
            ->get();

        $todayTotal = $todayTransactions->sum('total_amount');
        $monthTotal = $monthTransactions->sum('total_amount');

        return [
            'success'      => true,
            'today_total'  => (float) $todayTotal,
            'today_count'  => $todayTransactions->count(),
            'month_total'  => (float) $monthTotal,
            'transactions' => $todayTransactions->map(fn($t) => [
                'uuid' => $t->uuid,
                'receipt_number' => $t->receipt_number ?: $t->uuid,
                'created_at' => $t->created_at->toISOString(),
                'staff_name' => $t->staff ? $t->staff->user->firstname . ' ' . $t->staff->user->lastname : 'Admin',
                'total_amount' => (float) $t->total_amount,
                'status' => $t->status,
                'items_count' => $t->items()->count(),
            ])->values(),
        ];
    }
}
