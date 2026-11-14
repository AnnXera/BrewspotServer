<?php

namespace App\Notifications;

/**
 * In-app notice about what's happening at a branch: stock running out, a locked PIN,
 * a refund, spoilage. Sent to the branch's managers (and the owner when only they can act).
 */
class BranchNotice extends AppNotice
{
    // ---- Today's servings ----

    /** Fires once per item per day per threshold (the serving row is per day). */
    public static function servingsLow(
        string $branchUuid,
        string $servingUuid,
        string $itemName,
        int $remaining,
        int $expected,
        int $thresholdPercent = 0,
        ?string $branchName = null,
        bool $forOwner = false
    ): self {
        $remainingPercent = $expected > 0 ? (int) round(($remaining / $expected) * 100) : 0;
        $limitCopy = $thresholdPercent > 0 ? ", low-stock limit: {$thresholdPercent}%" : '';
        $dedupeKey = $thresholdPercent > 0
            ? "servings_low:{$servingUuid}:{$thresholdPercent}"
            : "servings_low:{$servingUuid}";

        if ($forOwner) {
            $where = $branchName ? " at {$branchName}" : '';

            return new self(
                'servings_low',
                "{$itemName} is running low{$where}",
                "{$remaining} of {$expected} servings left today{$where} ({$remainingPercent}% remaining{$limitCopy}).",
                "/owner/branches/{$branchUuid}",
                $dedupeKey
            );
        }

        return new self(
            'servings_low',
            "{$itemName} is running low ({$remainingPercent}% left)",
            "{$remaining} of {$expected} servings left today ({$remainingPercent}% remaining{$limitCopy}). Raise the limit in Servings if you expect more orders.",
            "/manager/servings?branch={$branchUuid}",
            $dedupeKey
        );
    }

    public static function soldOut(
        string $branchUuid,
        string $servingUuid,
        string $itemName,
        int $expected,
        ?string $branchName = null,
        bool $forOwner = false
    ): self {
        if ($forOwner) {
            $where = $branchName ? " at {$branchName}" : '';

            return new self(
                'sold_out',
                "{$itemName} is sold out{$where}",
                "All {$expected} servings for today{$where} are used, so the register can't sell it.",
                "/owner/branches/{$branchUuid}",
                "sold_out:{$servingUuid}"
            );
        }

        return new self(
            'sold_out',
            "{$itemName} is sold out",
            "All {$expected} servings for today are used, so the register can't sell it. Raise the limit in Servings to keep selling.",
            "/manager/servings?branch={$branchUuid}",
            "sold_out:{$servingUuid}"
        );
    }

    // ---- Register ----

    /** $forOwner: the owner gets the owner-side link (only they can reset a manager's PIN). */
    public static function pinLocked(string $branchUuid, string $userUuid, string $name, string $role, string $branchName, bool $forOwner = false): self
    {
        $link = $forOwner
            ? "/owner/branches/{$branchUuid}?tab=employees&employee={$userUuid}"
            : "/manager/cafe?branch={$branchUuid}&tab=employees&employee={$userUuid}";

        return new self('pin_locked', "{$name}'s PIN is locked",
            "{$name} ({$role}) entered a wrong PIN 5 times at {$branchName} and can't sign in to the register. Set a new PIN to let them back in.",
            $link);
    }

    /** To the owner: a manager picked a new PIN themselves (it approves voids and refunds). */
    public static function pinChanged(string $branchUuid, string $userUuid, string $name, string $branchName): self
    {
        return new self('pin_changed', "{$name} changed their PIN",
            "{$name} (Manager) set a new register PIN at {$branchName}. It approves voids and refunds. If you didn't expect this, set a new PIN for them.",
            "/owner/branches/{$branchUuid}?tab=employees&employee={$userUuid}");
    }

    /** To the owner and the branch's managers: someone set a new PIN for a cashier. */
    public static function cashierPinChanged(
        string $branchUuid,
        string $cashierUuid,
        string $cashierName,
        string $actorName,
        string $actorRole,
        string $branchName,
        bool $forOwner = false
    ): self {
        $link = $forOwner
            ? "/owner/branches/{$branchUuid}?tab=employees&employee={$cashierUuid}"
            : "/manager/cafe?branch={$branchUuid}&tab=employees&employee={$cashierUuid}";

        return new self('pin_changed', "{$cashierName}'s PIN was changed",
            "{$actorName} ({$actorRole}) set a new register PIN for {$cashierName} (Cashier) at {$branchName}.",
            $link);
    }

    public static function refundApproved(string $branchUuid, string $receiptNumber, string $amount, string $approverName, ?string $reason): self
    {
        $why = $reason ? " Reason: {$reason}" : '';

        return new self('refund_approved', 'Refund approved',
            "Receipt #{$receiptNumber} (₱{$amount}) was refunded, approved by {$approverName}.{$why}",
            "/manager/dashboard?branch={$branchUuid}");
    }

    /**
     * One running summary per branch per day. NotificationService::refreshForUsers()
     * rewrites it (and marks it unread) on every new entry instead of stacking notices.
     */
    public static function spoilageToday(string $branchUuid, string $date, int $entries, int $servings, string $latest): self
    {
        $s = $servings === 1 ? '' : 's';
        $e = $entries === 1 ? 'entry' : 'entries';

        return new self('spoilage_logged', 'Spoilage logged today',
            "{$servings} serving{$s} written off in {$entries} {$e}. Latest: {$latest}.",
            "/manager/servings?branch={$branchUuid}",
            "spoilage:{$branchUuid}:{$date}");
    }
}
