<?php

namespace App\Notifications;

/**
 * In-app notice about what's happening at a branch: stock running out, a locked PIN,
 * a refund, spoilage. Sent to the branch's managers (and the owner when only they can act).
 */
class BranchNotice extends AppNotice
{
    // ---- Today's servings ----

    /** Fires once per item per day (the serving row is per day). */
    public static function servingsLow(string $branchUuid, string $servingUuid, string $itemName, int $remaining, int $expected): self
    {
        return new self('servings_low', "{$itemName} is running low",
            "{$remaining} of {$expected} servings left today. Raise the limit in Servings if you expect more orders.",
            "/manager/servings?branch={$branchUuid}",
            "servings_low:{$servingUuid}");
    }

    public static function soldOut(string $branchUuid, string $servingUuid, string $itemName, int $expected): self
    {
        return new self('sold_out', "{$itemName} is sold out",
            "All {$expected} servings for today are used, so the register can't sell it. Raise the limit in Servings to keep selling.",
            "/manager/servings?branch={$branchUuid}",
            "sold_out:{$servingUuid}");
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
