<?php

namespace App\Services;

use App\Models\Cafe;
use App\Models\CafeBranch;
use App\Models\InventoryServing;
use App\Models\ServingSpoilageLog;
use App\Models\Transaction;
use App\Models\User;
use App\Notifications\BranchNotice;
use App\Repository\NotificationRepository;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

/**
 * Turns what happens on a branch's registers into bell notices for the people who
 * run it: its active managers and the cafe owner.
 *
 * Every method is called after the sale, refund or log is saved, and never throws:
 * a failed notice must not break the register.
 */
class BranchAlertService
{
    private const SPOILAGE_REASONS = [
        'expired'     => 'expired',
        'dropped'     => 'dropped or spilled',
        'wrong_order' => 'wrong order',
        'quality'     => 'quality issue',
        'end_of_day'  => 'leftover at closing',
        'other'       => 'other',
    ];

    public function __construct(
        private readonly NotificationRepository $repo,
        private readonly NotificationService $notifications
    ) {}

    /**
     * Remaining servings count at or below which an item is considered low stock
     * based on the cafe's configured low_stock_threshold_percent (1-99%).
     * Returns 0 when no threshold is configured or expected <= 1.
     */
    public static function lowRemainingThreshold(int $expected, ?int $thresholdPercent): int
    {
        if ($expected <= 1 || $thresholdPercent === null || $thresholdPercent <= 0) {
            return 0;
        }

        $percent = min(99, max(1, $thresholdPercent));
        $lowRemaining = max(1, (int) floor($expected * ($percent / 100)));

        return min($expected - 1, $lowRemaining);
    }

    /**
     * After servings were used (sold or spoiled): notify both the branch manager(s)
     * and the cafe owner when the item just crossed the cafe's low-stock percentage
     * limit or ran out. $usedBefore is InventoryServing::used() before the change.
     */
    public function servingsUsed(InventoryServing $serving, int $usedBefore): void
    {
        $this->safely('servings', function () use ($serving, $usedBefore) {
            $expected = (int) $serving->expected_servings;
            $used     = $serving->used();

            // Days without a target never run out.
            if ($expected <= 0 || $used <= $usedBefore) {
                return;
            }

            $serving->loadMissing(['menuItem', 'branch.cafe.owner']);
            $branch   = $serving->branch;
            if (! $branch) {
                return;
            }

            $cafe     = $branch->cafe;
            $owner    = $cafe?->owner;
            $itemName = $serving->menuItem?->menu_name ?? 'An item';
            $managers = $this->repo->activeManagersAtBranch($branch->branch_id);

            if ($usedBefore < $expected && $used >= $expected) {
                $this->notifications->notifyUsers(
                    $managers,
                    BranchNotice::soldOut($branch->uuid, $serving->uuid, $itemName, $expected, $branch->branch_name, forOwner: false)
                );

                if ($owner && $owner->status !== 'inactive') {
                    $this->notifications->notifyUser(
                        $owner,
                        BranchNotice::soldOut($branch->uuid, $serving->uuid, $itemName, $expected, $branch->branch_name, forOwner: true)
                    );
                }

                return;
            }

            $thresholdPercent = $cafe?->lowStockThresholdPercent();
            if ($thresholdPercent === null || $thresholdPercent <= 0) {
                return;
            }

            $lowRemaining    = self::lowRemainingThreshold($expected, $thresholdPercent);
            $remainingBefore = max(0, $expected - $usedBefore);
            $remaining       = max(0, $expected - $used);

            if (! ($lowRemaining > 0 && $remaining > 0 && $remaining <= $lowRemaining && $remainingBefore > $lowRemaining)) {
                return;
            }

            $this->notifications->notifyUsers(
                $managers,
                BranchNotice::servingsLow(
                    $branch->uuid,
                    $serving->uuid,
                    $itemName,
                    $remaining,
                    $expected,
                    $thresholdPercent,
                    $branch->branch_name,
                    forOwner: false
                )
            );

            if ($owner && $owner->status !== 'inactive') {
                $this->notifications->notifyUser(
                    $owner,
                    BranchNotice::servingsLow(
                        $branch->uuid,
                        $serving->uuid,
                        $itemName,
                        $remaining,
                        $expected,
                        $thresholdPercent,
                        $branch->branch_name,
                        forOwner: true
                    )
                );
            }
        });
    }

    /**
     * When the owner updates the cafe-wide low-stock percentage in Cafe Management
     * (or when daily limits are updated), check all active servings across the cafe's
     * branches today and notify both branch managers and the owner for any item
     * currently at or below the configured low-stock percentage.
     */
    public function evaluateCafeTodayServings(Cafe $cafe): void
    {
        $this->safely('servings_threshold_update', function () use ($cafe) {
            $thresholdPercent = $cafe->lowStockThresholdPercent();
            if ($thresholdPercent === null || $thresholdPercent <= 0) {
                return;
            }

            $cafe->loadMissing(['owner', 'branches']);
            $owner = $cafe->owner;
            $branchIds = $cafe->branches->pluck('branch_id')->all();

            if (empty($branchIds)) {
                return;
            }

            $servings = InventoryServing::with(['menuItem', 'branch'])
                ->whereIn('branch_id', $branchIds)
                ->whereDate('date', Carbon::today())
                ->where('is_sold_out', false)
                ->where('expected_servings', '>', 0)
                ->get();

            foreach ($servings as $serving) {
                $this->notifyIfCurrentlyLow($serving, $thresholdPercent, $owner);
            }
        });
    }

    /**
     * Check a single serving row against its cafe's low-stock percentage right now.
     */
    public function evaluateServingState(InventoryServing $serving): void
    {
        $this->safely('serving_state', function () use ($serving) {
            if ($serving->is_sold_out || (int) $serving->expected_servings <= 0) {
                return;
            }

            $serving->loadMissing(['menuItem', 'branch.cafe.owner']);
            $cafe = $serving->branch?->cafe;
            $thresholdPercent = $cafe?->lowStockThresholdPercent();

            if ($thresholdPercent === null || $thresholdPercent <= 0) {
                return;
            }

            $this->notifyIfCurrentlyLow($serving, $thresholdPercent, $cafe?->owner);
        });
    }

    private function notifyIfCurrentlyLow(InventoryServing $serving, int $thresholdPercent, ?User $owner): void
    {
        $expected  = (int) $serving->expected_servings;
        $remaining = $serving->remaining();
        $branch    = $serving->branch;

        if (! $branch || $expected <= 0 || $remaining <= 0) {
            return;
        }

        $lowRemaining = self::lowRemainingThreshold($expected, $thresholdPercent);
        if ($lowRemaining <= 0 || $remaining > $lowRemaining) {
            return;
        }

        $itemName = $serving->menuItem?->menu_name ?? 'An item';
        $managers = $this->repo->activeManagersAtBranch($branch->branch_id);

        $this->notifications->notifyUsers(
            $managers,
            BranchNotice::servingsLow(
                $branch->uuid,
                $serving->uuid,
                $itemName,
                $remaining,
                $expected,
                $thresholdPercent,
                $branch->branch_name,
                forOwner: false
            )
        );

        if ($owner && $owner->status !== 'inactive') {
            $this->notifications->notifyUser(
                $owner,
                BranchNotice::servingsLow(
                    $branch->uuid,
                    $serving->uuid,
                    $itemName,
                    $remaining,
                    $expected,
                    $thresholdPercent,
                    $branch->branch_name,
                    forOwner: true
                )
            );
        }
    }

    /**
     * A PIN was just locked after too many wrong tries. Managers can reset cashier and
     * staff PINs; a locked manager needs the owner (or another manager) to reset theirs.
     */
    public function pinLocked(CafeBranch $branch, User $locked): void
    {
        $this->safely('pin_locked', function () use ($branch, $locked) {
            // An owner resets their own PIN from the dashboard.
            if ($locked->isOwner()) {
                return;
            }

            $name = trim("{$locked->firstname} {$locked->lastname}");
            $role = $locked->roleName() ?? 'Staff';

            $managers = $this->repo->activeManagersAtBranch($branch->branch_id)
                ->reject(fn (User $u) => $u->user_id === $locked->user_id);

            $this->notifications->notifyUsers($managers,
                BranchNotice::pinLocked($branch->uuid, $locked->uuid, $name, $role, $branch->branch_name));

            if ($locked->isManager()) {
                $this->notifications->notifyUser($branch->loadMissing('cafe.owner')->cafe?->owner,
                    BranchNotice::pinLocked($branch->uuid, $locked->uuid, $name, $role, $branch->branch_name, forOwner: true));
            }
        });
    }

    /** Tell the branch's other managers; the one who approved it already knows. */
    public function refundApproved(CafeBranch $branch, Transaction $transaction, User $approver, ?string $reason): void
    {
        $this->safely('refund', function () use ($branch, $transaction, $approver, $reason) {
            $managers = $this->repo->activeManagersAtBranch($branch->branch_id)
                ->reject(fn (User $u) => $u->user_id === $approver->user_id);

            $this->notifications->notifyUsers($managers, BranchNotice::refundApproved(
                $branch->uuid,
                (string) $transaction->receipt_number,
                number_format((float) $transaction->total_amount, 2),
                trim("{$approver->firstname} {$approver->lastname}"),
                $reason ? trim($reason) : null,
            ));
        });
    }

    /** Keep the branch's one "spoilage today" summary current for each manager. */
    public function spoilageLogged(CafeBranch $branch, ServingSpoilageLog $latest): void
    {
        $this->safely('spoilage', function () use ($branch, $latest) {
            $today = ServingSpoilageLog::whereNull('voided_at')
                ->whereHas('serving', fn ($q) => $q
                    ->where('branch_id', $branch->branch_id)
                    ->whereDate('date', Carbon::today()))
                ->get(['spoilage_log_id', 'quantity']);

            $latest->loadMissing('serving.menuItem');
            $item   = trim("{$latest->size_name} {$latest->serving?->menuItem?->menu_name}") ?: 'item';
            $reason = self::SPOILAGE_REASONS[$latest->reason] ?? null;

            $this->notifications->refreshForUsers(
                $this->repo->activeManagersAtBranch($branch->branch_id),
                BranchNotice::spoilageToday(
                    $branch->uuid,
                    Carbon::today()->toDateString(),
                    $today->count(),
                    (int) $today->sum('quantity'),
                    "{$latest->quantity} {$item}" . ($reason ? " ({$reason})" : ''),
                )
            );
        });
    }

    private function safely(string $event, callable $fn): void
    {
        try {
            $fn();
        } catch (\Throwable $e) {
            Log::warning('Branch alert failed.', ['event' => $event, 'error' => $e->getMessage()]);
        }
    }
}
