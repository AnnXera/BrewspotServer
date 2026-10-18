<?php

namespace App\Services;

use App\Http\Resources\ServingSpoilageLogResource;
use App\Models\CafeBranch;
use App\Models\InventoryServing;
use App\Models\MenuItem;
use App\Models\PosDevice;
use App\Models\ServingSpoilageLog;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Spoiled servings: logged by staff on the register, voided by an owner or
 * manager from the dashboard. Both only touch today's servings.
 *
 * Each entry is its own row, and inventory_servings.spoilage_qty is kept as
 * the total of the entries that are not voided. Both are changed in the same
 * transaction with the serving row locked, so two registers logging at once
 * can't push sold + spoilage past the day's expected servings.
 *
 * Results carry an `http` key that the controller turns into the status code.
 */
class SpoilageService
{
    // ── On the register (device token) ───────────────────────────────────

    /**
     * Items in sizes need the size thrown away; its price (or the base price
     * for items without sizes) is kept on the entry for the spoilage cost.
     */
    public function logOnDevice(PosDevice $device, string $itemUuid, ?string $sizeUuid, int $quantity, ?string $reason): array
    {
        if (! $device->active_staff_id) {
            return ['success' => false, 'http' => 403, 'message' => 'Register is locked.'];
        }

        $item = MenuItem::where('uuid', $itemUuid)
            ->where('cafe_id', $device->branch->cafe_id)
            ->with('sizes')
            ->first();

        if (! $item) {
            return ['success' => false, 'http' => 404, 'message' => 'Menu item not found.'];
        }

        $size = null;

        if ($item->sizes->isNotEmpty()) {
            $size = $item->sizes->firstWhere('uuid', $sizeUuid);

            if (! $size) {
                return ['success' => false, 'http' => 422, 'message' => "Pick the size of the spoiled {$item->menu_name}."];
            }
        } elseif ($sizeUuid) {
            return ['success' => false, 'http' => 422, 'message' => "{$item->menu_name} does not come in sizes."];
        }

        $itemId    = $item->men_item_id;
        $unitValue = (float) ($size?->price ?? $item->base_price);

        $result = DB::transaction(function () use ($device, $itemId, $size, $unitValue, $quantity, $reason) {
            $serving = InventoryServing::where('men_item_id', $itemId)
                ->where('branch_id', $device->branch_id)
                ->whereDate('date', $this->today())
                ->lockForUpdate()
                ->first();

            if (! $serving) {
                return ['success' => false, 'http' => 422, 'message' => 'This item has no servings set for today.'];
            }

            $left = $serving->expected_servings - $serving->servings_sold - $serving->spoilage_qty;

            if ($quantity > $left) {
                return [
                    'success' => false,
                    'http'    => 422,
                    'message' => $left > 0
                        ? "Only {$left} serving" . ($left === 1 ? '' : 's') . ' left today, so you can log at most that many.'
                        : 'No servings are left today to log as spoiled.',
                ];
            }

            $log = ServingSpoilageLog::create([
                'servings_id' => $serving->servings_id,
                'men_size_id' => $size?->men_size_id,
                'size_name'   => $size?->name,
                'staff_id'    => $device->active_staff_id,
                'device_id'   => $device->device_id,
                'quantity'    => $quantity,
                'unit_value'  => $unitValue,
                'reason'      => $reason,
            ]);

            $serving->increment('spoilage_qty', $quantity);

            return ['success' => true, 'log' => $log];
        });

        if (! $result['success']) {
            return $result;
        }

        $log = $this->loadForDisplay($result['log']);

        Log::channel('owner')->info('Spoilage logged on register.', [
            'device_uuid' => $device->uuid,
            'log_uuid'    => $log->uuid,
            'item_uuid'   => $itemUuid,
            'size'        => $log->size_name,
            'quantity'    => $quantity,
            'reason'      => $reason,
        ]);

        $name = trim("{$log->size_name} {$log->serving->menuItem?->menu_name}");

        return [
            'success' => true,
            'http'    => 201,
            'message' => "Logged {$quantity} spoiled {$name}.",
            'log'     => new ServingSpoilageLogResource($log),
        ];
    }

    public function listForDevice(PosDevice $device): array
    {
        return $this->listResult($this->todaysLogs($device->branch_id));
    }

    // ── Dashboard (owner / manager, branch.access) ───────────────────────

    public function listForBranch(CafeBranch $branch): array
    {
        return $this->listResult($this->todaysLogs($branch->branch_id));
    }

    public function void(User $actor, CafeBranch $branch, string $logUuid): array
    {
        $result = DB::transaction(function () use ($actor, $branch, $logUuid) {
            $log = ServingSpoilageLog::where('uuid', $logUuid)
                ->whereHas('serving', fn ($q) => $q
                    ->where('branch_id', $branch->branch_id)
                    ->whereDate('date', $this->today()))
                ->lockForUpdate()
                ->first();

            if (! $log) {
                return ['success' => false, 'http' => 404, 'message' => 'Spoilage entry not found for today.'];
            }

            if ($log->isVoided()) {
                return ['success' => false, 'http' => 422, 'message' => 'This entry is already voided.'];
            }

            $serving = InventoryServing::whereKey($log->servings_id)->lockForUpdate()->first();

            $log->update(['voided_at' => now(), 'voided_by' => $actor->user_id]);
            $serving->decrement('spoilage_qty', min($log->quantity, $serving->spoilage_qty));

            return ['success' => true, 'log' => $log];
        });

        if (! $result['success']) {
            return $result;
        }

        $log = $this->loadForDisplay($result['log']);

        Log::channel('owner')->info('Spoilage entry voided.', [
            'actor_uuid'  => $actor->uuid,
            'branch_uuid' => $branch->uuid,
            'log_uuid'    => $log->uuid,
            'quantity'    => $log->quantity,
        ]);

        return [
            'success' => true,
            'message' => 'Spoilage entry voided.',
            'log'     => new ServingSpoilageLogResource($log),
        ];
    }

    // ── Shared ───────────────────────────────────────────────────────────

    private function todaysLogs(int $branchId): Collection
    {
        return ServingSpoilageLog::whereHas('serving', fn ($q) => $q
                ->where('branch_id', $branchId)
                ->whereDate('date', $this->today()))
            ->with($this->displayRelations())
            ->latest('spoilage_log_id')
            ->get();
    }

    private function listResult(Collection $logs): array
    {
        return [
            'success' => true,
            'date'    => $this->today()->toDateString(),
            'total'   => (int) $logs->whereNull('voided_at')->sum('quantity'),
            'reasons' => ServingSpoilageLog::REASONS,
            'logs'    => ServingSpoilageLogResource::collection($logs),
        ];
    }

    private function loadForDisplay(ServingSpoilageLog $log): ServingSpoilageLog
    {
        return $log->fresh($this->displayRelations());
    }

    private function displayRelations(): array
    {
        return [
            'serving.menuItem' => fn ($q) => $q->withTrashed(),
            'staff.user',
            'device',
            'voidedBy',
        ];
    }

    private function today(): Carbon
    {
        return Carbon::today();
    }
}
