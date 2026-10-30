<?php

namespace App\Services;

use App\Jobs\SendSubscriptionNotice;
use App\Mail\SubscriptionExpiredMail;
use App\Mail\SubscriptionExpiringMail;
use App\Models\Subscription;
use App\Models\SubscriptionNotice;
use App\Models\SubscriptionPlan;
use App\Notifications\OwnerNotice;
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
        private readonly NotificationService $notifications,
    ) {}

    /**
     * "Ending soon" reminders at 7, 3 and 1 day(s) before the term ends.
     *
     * Each term is only offered the reminder for the window it is in now (end_date after the
     * next-smaller window), so a term that is already 2 days out gets the 3-day reminder
     * alone, never a late 7-day one alongside it. With GRACE_DAYS at 1 the 1-day reminder
     * goes out as renewal opens, so it also tells the owner they can pay now.
     */
    public function sendExpirationReminders(): int
    {
        $count = 0;
        $after = Carbon::now();

        foreach (SubscriptionNotice::EXPIRING_DAYS as $window) {
            $until = Carbon::now()->addDays($window);
            $kind  = SubscriptionNotice::expiringKind($window);

            foreach ($this->subscriptionRepo->findExpiringBetween($after, $until, $kind) as $subscription) {
                $count += (int) $this->sendExpiring($subscription, $kind, $window);
            }

            $after = $until;
        }

        return $count;
    }

    private function sendExpiring(Subscription $subscription, string $kind, int $window): bool
    {
        $daysRemaining = max(0, (int) Carbon::now()->startOfDay()->diffInDays($subscription->end_date->startOfDay(), false));
        $renewPlanName = $this->renewPlan($subscription)?->sub_name;
        $renewalOpen   = $subscription->isRenewalOpen();
        $endDate       = $subscription->end_date->format('F j, Y');

        return $this->queue($subscription, $kind, fn ($owner) => new SubscriptionExpiringMail(
            ownerName: $this->ownerName($owner),
            planName: $subscription->plan->sub_name,
            endDate: $endDate,
            daysRemaining: $daysRemaining,
            renewUrl: $this->buildRenewUrl($subscription),
            renewPlanName: $renewPlanName,
            renewalOpensOn: $subscription->renewalOpensAt()?->format('F j, Y'),
            renewalOpen: $renewalOpen,
        ), OwnerNotice::subscriptionExpiring(
            $subscription->uuid,
            $subscription->plan->sub_name,
            $endDate,
            $daysRemaining,
            $window,
            $renewalOpen,
            $renewPlanName,
        ));
    }

    /**
     * The term ran out and the owner hasn't started another one.
     */
    public function sendExpiredNotices(): int
    {
        $count = 0;

        foreach ($this->subscriptionRepo->findRecentlyExpiredUnnotified(self::EXPIRED_NOTICE_WINDOW_DAYS) as $subscription) {
            $sent = $this->queue($subscription, SubscriptionNotice::KIND_EXPIRED, fn ($owner) => new SubscriptionExpiredMail(
                ownerName: $this->ownerName($owner),
                planName: $subscription->plan->sub_name,
                renewPlanName: $this->renewPlan($subscription)?->sub_name,
                endedOn: $subscription->end_date->format('F j, Y'),
                renewUrl: $this->buildRenewUrl($subscription),
            ), OwnerNotice::subscriptionExpired(
                $subscription->uuid,
                $subscription->plan->sub_name,
                $subscription->end_date->format('F j, Y'),
            ));

            $count += (int) $sent;
        }

        return $count;
    }

    /**
     * Claims the notice, posts it to the owner's bell, and queues the email. The job releases
     * the claim if sending ultimately fails; the bell notice's dedupe key keeps that retry
     * from posting it twice.
     */
    private function queue(Subscription $subscription, string $kind, callable $buildMail, OwnerNotice $notice): bool
    {
        $owner = $subscription->user;

        if (! $owner || ! $this->subscriptionRepo->claimNotice($subscription, $kind)) {
            return false;
        }

        $this->notifications->notifyOwner($owner, $notice);

        /** @var Mailable $mail */
        $mail = $buildMail($owner);

        SendSubscriptionNotice::dispatch($subscription->sub_id, $kind, $owner->email, $mail);

        Log::channel('owner')->info('Subscription notice queued.', [
            'owner_uuid'        => $owner->uuid,
            'subscription_uuid' => $subscription->uuid,
            'notice'            => $kind,
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
