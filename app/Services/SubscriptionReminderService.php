<?php

namespace App\Services;

use App\Jobs\SendSubscriptionNotice;
use App\Mail\SubscriptionExpiredMail;
use App\Mail\SubscriptionExpiringMail;
use App\Mail\SubscriptionRenewalOpenMail;
use App\Models\Subscription;
use App\Models\SubscriptionPlan;
use App\Repository\SubscriptionRepository;
use Illuminate\Mail\Mailable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class SubscriptionReminderService
{
    /** How far back a lapsed term still gets an "expired" email. */
    private const EXPIRED_NOTICE_WINDOW_DAYS = 3;

    public function __construct(
        private readonly SubscriptionRepository $subscriptionRepo,
    ) {}

    /**
     * Early heads-up, a few days before the term ends.
     */
    public function sendExpirationReminders(int $withinDays = 3): int
    {
        $count = 0;

        foreach ($this->subscriptionRepo->findExpiringWithinDays($withinDays) as $subscription) {
            $daysRemaining = max(0, (int) Carbon::now()->startOfDay()->diffInDays($subscription->end_date->startOfDay(), false));
            $renewPlan     = $this->renewPlan($subscription);

            $sent = $this->queue($subscription, 'expiration_reminder_sent_at', fn ($owner) => new SubscriptionExpiringMail(
                ownerName: $this->ownerName($owner),
                planName: $subscription->plan->sub_name,
                endDate: $subscription->end_date->format('F j, Y'),
                daysRemaining: $daysRemaining,
                renewUrl: $this->buildRenewUrl($subscription),
                renewPlanName: $renewPlan?->sub_name ?? $subscription->plan->sub_name,
                renewalOpensOn: $subscription->renewalOpensAt()?->format('F j, Y'),
            ));

            $count += (int) $sent;
        }

        return $count;
    }

    /**
     * The owner can pay for their next term (or booked plan change) from now on.
     */
    public function sendRenewalOpenReminders(): int
    {
        $count = 0;

        foreach ($this->subscriptionRepo->findRenewalOpenUnnotified() as $subscription) {
            $sent = $this->queue($subscription, 'renewal_open_reminder_sent_at', fn ($owner) => new SubscriptionRenewalOpenMail(
                ownerName: $this->ownerName($owner),
                planName: $subscription->plan->sub_name,
                renewPlanName: $this->renewPlan($subscription)?->sub_name,
                endDate: $subscription->end_date->format('F j, Y'),
                renewUrl: $this->buildRenewUrl($subscription),
            ));

            $count += (int) $sent;
        }

        return $count;
    }

    /**
     * The term ran out and the owner hasn't started another one.
     */
    public function sendExpiredNotices(): int
    {
        $count = 0;

        foreach ($this->subscriptionRepo->findRecentlyExpiredUnnotified(self::EXPIRED_NOTICE_WINDOW_DAYS) as $subscription) {
            $sent = $this->queue($subscription, 'expired_notice_sent_at', fn ($owner) => new SubscriptionExpiredMail(
                ownerName: $this->ownerName($owner),
                planName: $subscription->plan->sub_name,
                renewPlanName: $this->renewPlan($subscription)?->sub_name,
                endedOn: $subscription->end_date->format('F j, Y'),
                renewUrl: $this->buildRenewUrl($subscription),
            ));

            $count += (int) $sent;
        }

        return $count;
    }

    /**
     * Claims the notice and queues it. The job releases the claim if sending ultimately fails.
     */
    private function queue(Subscription $subscription, string $marker, callable $buildMail): bool
    {
        $owner = $subscription->user;

        if (! $owner || ! $this->subscriptionRepo->claimNotice($subscription, $marker)) {
            return false;
        }

        /** @var Mailable $mail */
        $mail = $buildMail($owner);

        SendSubscriptionNotice::dispatch($subscription->sub_id, $marker, $owner->email, $mail);

        Log::channel('owner')->info('Subscription notice queued.', [
            'owner_uuid'        => $owner->uuid,
            'subscription_uuid' => $subscription->uuid,
            'notice'            => $marker,
            'end_date'          => $subscription->end_date?->toDateString(),
        ]);

        return true;
    }

    private function ownerName($owner): string
    {
        return $owner->firstname ?? $owner->username ?? 'there';
    }

    /**
     * What the owner renews into: a booked plan change wins over the current plan. A trial
     * can't be bought again, so without a booked change there is no single plan to offer.
     */
    private function renewPlan(Subscription $subscription): ?SubscriptionPlan
    {
        if ($subscription->pendingPlan) {
            return $subscription->pendingPlan;
        }

        return Str::contains(strtolower($subscription->plan->sub_name), 'trial') ? null : $subscription->plan;
    }

    /**
     * Deep link to the owner's subscription page with the renewal plan preselected.
     *
     * The owner signs in there and pays through the existing checkout modal, so renewal
     * reuses the same gateway path as a first-time purchase — no payment link is embedded
     * in the email itself, and nothing is chargeable without an authenticated session.
     */
    private function buildRenewUrl(Subscription $subscription): string
    {
        $base = rtrim(config('app.frontend_url'), '/') . '/owner/subscription';
        $plan = $this->renewPlan($subscription);

        if (! $plan) {
            return $base;
        }

        $cycle = $subscription->pending_billing_cycle ?? $subscription->billing_cycle ?? 'monthly';

        return $base . '?' . http_build_query([
            'renew' => $plan->uuid,
            'cycle' => $cycle === 'trial' ? 'monthly' : $cycle,
        ]);
    }
}
