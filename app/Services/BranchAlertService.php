<?php

namespace App\Services;

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
 * run it: its active managers, plus the owner when only they can act.
 *
 * Every method is called after the sale, refund or log is saved, and never throws:
 * a failed notice must not break the register.
 */
class BranchAlertService
{
    /** "Running low" once this share of the day's servings is used. */
    private const LOW_SHARE = 0.8;

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
     * After servings were used (sold or spoiled): notify when the item just crossed
     * "running low" or ran out. $usedBefore is InventoryServing::used() before the change.
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

            $serving->loadMissing(['menuItem', 'branch']);
            $branch   = $serving->branch;
            $itemName = $serving->menuItem?->menu_name ?? 'An item';

            if ($usedBefore < $expected && $used >= $expected) {
                $notice = BranchNotice::soldOut($branch->uuid, $serving->uuid, $itemName, $expected);
            } else {
                $lowAt = (int) ceil($expected * self::LOW_SHARE);

                if (! ($usedBefore < $lowAt && $used >= $lowAt && $used < $expected)) {
                    return;
                }

                $notice = BranchNotice::servingsLow($branch->uuid, $serving->uuid, $itemName, $expected - $used, $expected);
            }

            $this->notifications->notifyUsers($this->repo->activeManagersAtBranch($branch->branch_id), $notice);
        });
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
