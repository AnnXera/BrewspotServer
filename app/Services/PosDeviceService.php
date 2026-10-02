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
use App\Models\TransactionItem;
use App\Models\Payment;
use App\Http\Resources\MenuCategoryResource;
use App\Http\Resources\MenuItemResource;
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
            'device'  => new PosDeviceResource($device->load(['branch.cafe', 'registeredBy', 'activeStaff.user'])),
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

    public function menu(PosDevice $device): array
    {
        $cafeId = $device->branch->cafe_id;
        
        $categories = MenuCategory::where('cafe_id', $cafeId)->orderBy('name')->get();
        
        $items = MenuItem::where('cafe_id', $cafeId)
            ->where('is_available', true)
            ->with('category')
            ->orderBy('menu_name')
            ->get();
            
        return [
            'success' => true,
            'categories' => MenuCategoryResource::collection($categories)->resolve(),
            'items' => MenuItemResource::collection($items)->resolve(),
        ];
    }

    public function transactions(PosDevice $device): array
    {
        $today = Carbon::today();
        $thisMonth = Carbon::now()->startOfMonth();

        $todayTotal = Transaction::where('branch_id', $device->branch_id)
            ->whereDate('created_at', $today)
            ->where('status', 'completed')
            ->sum('total_amount');

        $todayCount = Transaction::where('branch_id', $device->branch_id)
            ->whereDate('created_at', $today)
            ->where('status', 'completed')
            ->count();

        $monthTotal = Transaction::where('branch_id', $device->branch_id)
            ->where('created_at', '>=', $thisMonth)
            ->where('status', 'completed')
            ->sum('total_amount');

        $transactions = Transaction::where('branch_id', $device->branch_id)
            ->with(['staff.user', 'items.menuItem'])
            ->orderBy('created_at', 'desc')
            ->limit(50)
            ->get();

        return [
            'success' => true,
            'today_total' => $todayTotal,
            'today_count' => $todayCount,
            'month_total' => $monthTotal,
            'transactions' => $transactions->map(fn($t) => [
                'uuid' => $t->uuid,
                'receipt_number' => $t->receipt_number,
                'staff_name' => $t->staff ? trim($t->staff->user->firstname . ' ' . $t->staff->user->lastname) : 'Unknown',
                'total_amount' => $t->total_amount,
                'status' => $t->status,
                'created_at' => $t->created_at->toISOString(),
                'items_count' => $t->items->sum('quantity'),
            ])->values(),
        ];
    }

    public function checkout(PosDevice $device, array $data): array
    {
        if (!$device->active_staff_id) {
            abort(403, 'No active staff assigned to this register');
        }

        return DB::transaction(function () use ($device, $data) {
            $totalAmount = 0;
            $itemsToInsert = [];
            
            foreach ($data['items'] as $reqItem) {
                $menuItem = MenuItem::where('uuid', $reqItem['uuid'])->firstOrFail();
                $totalAmount += $menuItem->base_price * $reqItem['quantity'];
                
                $itemsToInsert[] = [
                    'menuItem' => $menuItem,
                    'quantity' => $reqItem['quantity'],
                    'unit_price' => $menuItem->base_price,
                ];
            }
            
            $subtotal = $totalAmount;
            $tax = $subtotal * 0.12;
            $finalTotal = $subtotal + $tax;

            $latestTransactionId = Transaction::max('transaction_id') ?? 0;
            $nextNumber = $latestTransactionId + 1;
            $receiptNumber = 'CF' . str_pad($nextNumber, 3, '0', STR_PAD_LEFT);

            $transaction = Transaction::create([
                'branch_id' => $device->branch_id,
                'staff_id' => $device->active_staff_id,
                'total_amount' => $finalTotal,
                'receipt_number' => $receiptNumber,
                'status' => 'completed',
            ]);

            foreach ($itemsToInsert as $insertData) {
                TransactionItem::create([
                    'transaction_id' => $transaction->transaction_id,
                    'men_item_id' => $insertData['menuItem']->men_item_id,
                    'quantity' => $insertData['quantity'],
                    'unit_price' => $insertData['unit_price'],
                ]);
            }
            
            Payment::create([
                'user_id' => $device->activeStaff->user_id,
                'payable_type' => Transaction::class,
                'payable_id' => $transaction->transaction_id,
                'amount' => $finalTotal,
                'amount_tendered' => $data['amount_tendered'],
                'amount_change' => max(0, $data['amount_tendered'] - $finalTotal),
                'payment_method_type' => $data['payment_method'],
                'status' => 'completed',
            ]);

            return [
                'success' => true,
                'message' => 'Order completed successfully',
                'transaction_uuid' => $transaction->uuid,
                'receipt_number' => $transaction->receipt_number,
            ];
        });
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
}
