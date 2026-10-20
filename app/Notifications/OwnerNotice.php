<?php

namespace App\Notifications;

use Illuminate\Notifications\Notification;

/**
 * In-app notice for a cafe owner (account decisions, branch reviews, billing).
 * One class for every kind: the bell only needs kind, copy and where to go.
 *
 * `dedupeKey` marks notices that can be triggered more than once for the same
 * event (gateway webhook retries, reminder re-queues); NotificationService skips
 * a notice whose key the owner already has.
 */
class OwnerNotice extends Notification
{
    public function __construct(
        public readonly string $kind,
        public readonly string $title,
        public readonly string $body,
        public readonly ?string $link = null,
        public readonly ?string $dedupeKey = null
    ) {}

    // ---- Account and branch decisions (admin) ----

    public static function accountStatus(string $status, ?string $reason = null): self
    {
        return match ($status) {
            'approved'  => new self('account_approved', 'Your application was approved',
                'Welcome to Brewspot. Your cafe account is active, so you can set up your menu, branches and staff.', '/owner/dashboard'),
            'rejected'  => new self('account_rejected', 'Your application was not approved',
                $reason ? "Reason: {$reason}" : 'Check your email for details on what to fix before applying again.'),
            'suspended' => new self('account_suspended', 'Your account was suspended',
                'Check your email for details, or contact Brewspot support to have it reviewed.'),
            default     => new self('account_reactivated', 'Your account is active again',
                'Your cafes and staff can use Brewspot again.', '/owner/dashboard'),
        };
    }

    public static function branchStatus(string $branchName, string $branchUuid, string $status, ?string $reason = null): self
    {
        return $status === 'approved'
            ? new self('branch_approved', 'Branch approved',
                "{$branchName} is now active. You can add staff and set its menu.", "/owner/branches/{$branchUuid}")
            : new self('branch_rejected', 'Branch not approved',
                $reason ? "{$branchName}: {$reason}" : "{$branchName} was not approved. Check your email for details.", '/owner/cafes');
    }

    // ---- Subscription and billing ----

    public static function paymentReceived(string $subscriptionUuid, string $planName, string $amount, ?string $endDate): self
    {
        $until = $endDate ? " Your plan runs until {$endDate}." : '';

        return new self('payment_received', 'Payment received',
            "₱{$amount} for the {$planName}.{$until}", '/owner/subscription',
            "payment_received:{$subscriptionUuid}:" . ($endDate ?? ''));
    }

    public static function paymentFailed(string $subscriptionUuid, string $planName, ?string $endDate): self
    {
        return new self('payment_failed', 'Payment failed',
            "We couldn't charge you for the {$planName}. Open your subscription to pay again and keep your plan.", '/owner/subscription',
            "payment_failed:{$subscriptionUuid}:" . ($endDate ?? ''));
    }

    // $fromGateway: the event came from a payment-gateway webhook (or a call that a webhook
    // repeats), so it may arrive more than once. Owner-initiated changes can legitimately
    // happen again (cancel, resume, cancel), so they are never de-duplicated.

    public static function planChangeScheduled(string $subscriptionUuid, string $newPlanName, string $effectiveDate, bool $fromGateway = false): self
    {
        return new self('plan_change_scheduled', 'Plan change scheduled',
            "You'll move to the {$newPlanName} on {$effectiveDate}.", '/owner/subscription',
            $fromGateway ? "plan_change:{$subscriptionUuid}:{$newPlanName}" : null);
    }

    public static function subscriptionCancelled(string $subscriptionUuid, string $planName, string $endDate, bool $fromGateway = false): self
    {
        return new self('subscription_cancelled', 'Subscription cancelled',
            "Your {$planName} won't renew. You keep full access until {$endDate}.", '/owner/subscription',
            $fromGateway ? "cancelled:{$subscriptionUuid}:{$endDate}" : null);
    }

    public static function subscriptionExpiring(string $subscriptionUuid, string $planName, string $endDate, int $daysRemaining): self
    {
        $when = match (true) {
            $daysRemaining <= 0 => 'today',
            $daysRemaining === 1 => 'tomorrow',
            default => "in {$daysRemaining} days",
        };

        return new self('subscription_expiring', 'Subscription ending soon',
            "Your {$planName} ends {$when} ({$endDate}). Renew to keep your branches running.", '/owner/subscription',
            "expiring:{$subscriptionUuid}:{$endDate}");
    }

    public static function renewalOpen(string $subscriptionUuid, string $planName, string $endDate): self
    {
        return new self('renewal_open', 'You can renew now',
            "Pay for your next term of the {$planName} any time before {$endDate}.", '/owner/subscription',
            "renewal_open:{$subscriptionUuid}:{$endDate}");
    }

    public static function subscriptionExpired(string $subscriptionUuid, string $planName, string $endedOn): self
    {
        return new self('subscription_expired', 'Subscription expired',
            "Your {$planName} ended on {$endedOn}. Renew to turn your plan features back on.", '/owner/subscription',
            "expired:{$subscriptionUuid}:{$endedOn}");
    }

    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function toArray(object $notifiable): array
    {
        return array_filter([
            'kind'       => $this->kind,
            'title'      => $this->title,
            'body'       => $this->body,
            'link'       => $this->link,
            'dedupe_key' => $this->dedupeKey,
        ], fn ($value) => $value !== null);
    }
}
