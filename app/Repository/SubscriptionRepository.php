<?php

namespace App\Repository;

use App\Models\Subscription;
use App\Models\SubscriptionPlan;
use Illuminate\Support\Carbon;

class SubscriptionRepository
{
    public function findTrialPlan(): ?SubscriptionPlan
    {
        return SubscriptionPlan::where('sub_name', 'Trial Plan')->first();
    }

    public function createTrialSubscription(int $userId, SubscriptionPlan $plan): Subscription
    {
        $subscription = Subscription::create([
            'user_id'              => $userId,
            'sub_plan_id'          => $plan->sub_plan_id,
            'start_date'           => Carbon::now(),
            'end_date'             => Carbon::now()->addDays($plan->duration_days),
            'status'               => 'active',
            'billing_cycle'        => 'trial',
            'cancel_at_period_end' => false,
        ]);

        $subscription->payments()->create([
            'uuid'                => (string) \Illuminate\Support\Str::uuid(),
            'user_id'             => $userId,
            'amount'              => 0,
            'status'              => 'succeeded',
            'payment_method_type' => 'Free Trial',
            'gateway_transaction_id' => 'TRIAL-' . strtoupper(\Illuminate\Support\Str::random(7)),
        ]);

        return $subscription;
    }

    public function findCurrentByUserId(int $userId): ?Subscription
    {
        return Subscription::where('user_id', $userId)
            ->where('status', 'active')
            ->with(['plan.features', 'pendingPlan.features'])
            ->latest('start_date')
            ->first();
    }

    /**
     * The owner's most recently finished term, whatever became of it.
     *
     * Once a term lapses the owner has no active row, but what they were on — and any plan
     * change they booked before it ran out — is still what they should be offered on their
     * way back in, so the subscription page can pick up where they left off.
     */
    public function findLatestEndedByUserId(int $userId): ?Subscription
    {
        return Subscription::where('user_id', $userId)
            ->whereIn('status', ['expired', 'cancelled'])
            ->whereNotNull('end_date')
            ->with(['plan.features', 'pendingPlan.features'])
            // Terms are sequential, so the newest row is the latest term. Ordering by end_date
            // would instead favour a row cancelled early while its end date was still ahead.
            ->orderByDesc('sub_id')
            ->first();
    }

    public function findHistoryByUserId(int $userId, int $perPage = 15)
    {
        return Subscription::where('user_id', $userId)
            ->with(['plan.features', 'latestPayment'])
            ->latest('start_date')
            ->paginate($perPage);
    }

    public function hasAnyByUserId(int $userId): bool
    {
        return Subscription::where('user_id', $userId)->exists();
    }

    public function findByUuid(string $uuid): ?Subscription
    {
        return Subscription::where('uuid', $uuid)->with(['plan.features', 'user'])->first();
    }

    public function createPending(int $userId, SubscriptionPlan $plan, string $billingCycle = 'monthly'): Subscription
    {
        return Subscription::create([
            'user_id'              => $userId,
            'sub_plan_id'          => $plan->sub_plan_id,
            'start_date'           => null,
            'end_date'             => null,
            'status'               => 'pending',
            'billing_cycle'        => $billingCycle,
            'cancel_at_period_end' => false,
        ]);
    }

    public function activate(Subscription $subscription): Subscription
    {
        $subscription->loadMissing('plan');

        $termDays = match ($subscription->billing_cycle) {
            'yearly' => 366, // 365 + 1 day grace period
            'daily'  => 2,   // 1 + 1 day grace period
            'trial'  => $subscription->plan->duration_days ?? 15,
            default  => 31,  // 30 + 1 day grace period for monthly
        };

        // Renewing early shouldn't cost the owner the days they already paid for, so the
        // new term starts when the outgoing one would have ended rather than today. The
        // outgoing subscription is still active here — it gets cancelled straight after.
        // Anchoring to its end date carries the remainder over exactly, with no day-rounding.
        $outgoingEnd = $subscription->billing_cycle === 'trial'
            ? null
            : $this->currentTermEnd($subscription);

        $endDate = ($outgoingEnd ?? Carbon::now())->copy()->addDays($termDays);

        $subscription->update([
            'start_date' => Carbon::now(),
            'end_date'   => $endDate,
            'status'     => 'active',
        ]);

        return $subscription->fresh(['plan', 'user']);
    }

    /**
     * End date of the owner's outgoing *paid* subscription, if one is still running.
     *
     * Only days the owner actually paid for are worth carrying over. A free trial that is
     * still running is skipped, so leaving a trial starts the paid term from today instead
     * of handing the owner the trial's unused days on top of what they just bought.
     */
    private function currentTermEnd(Subscription $incoming): ?Carbon
    {
        return Subscription::where('user_id', $incoming->user_id)
            ->where('sub_id', '!=', $incoming->sub_id)
            ->where('status', 'active')
            ->where('billing_cycle', '!=', 'trial')
            ->whereHas('plan', fn ($q) => $q->where('price', '>', 0))
            ->whereNotNull('end_date')
            ->where('end_date', '>', Carbon::now())
            ->orderByDesc('end_date')
            ->first()
            ?->end_date;
    }

    public function markFailed(Subscription $subscription): Subscription
    {
        $subscription->update(['status' => 'cancelled']);

        return $subscription->fresh(['plan', 'user']);
    }

    public function findActiveByUserAndPlan(int $userId, int $subPlanId): ?Subscription
    {
        return Subscription::where('user_id', $userId)
            ->where('sub_plan_id', $subPlanId)
            ->where('status', 'active')
            ->with('plan')
            ->latest('end_date')
            ->first();
    }

    /**
     * Book a plan change to take effect once the current term ends.
     *
     * Nothing is charged here. The choice is parked on the subscription the owner is
     * already running, and only becomes real when they pay for the next term — at which
     * point checkout creates a fresh subscription on the booked plan and this row is
     * cancelled, taking the booking with it.
     */
    public function schedulePlanChange(Subscription $subscription, SubscriptionPlan $plan, string $billingCycle): Subscription
    {
        $subscription->update([
            'pending_sub_plan_id'   => $plan->sub_plan_id,
            'pending_billing_cycle' => $billingCycle,
        ]);

        return $subscription->fresh(['plan.features', 'pendingPlan.features', 'user']);
    }

    /**
     * Drop a booked plan change, leaving the owner on their current plan.
     */
    public function clearPendingChange(Subscription $subscription): Subscription
    {
        $subscription->update([
            'pending_sub_plan_id'   => null,
            'pending_billing_cycle' => null,
        ]);

        return $subscription->fresh(['plan.features', 'user']);
    }

    /**
     * Gateway-managed subscriptions bill themselves, so owners on them are never asked to pay.
     */
    private function manuallyRenewed()
    {
        return Subscription::whereNull('gateway_subscription_id');
    }

    public function findExpiringWithinDays(int $days)
    {
        return $this->manuallyRenewed()
            ->where('status', 'active')
            ->whereNotNull('end_date')
            ->whereNull('expiration_reminder_sent_at')
            ->whereBetween('end_date', [Carbon::now(), Carbon::now()->addDays($days)])
            ->with(['plan', 'pendingPlan', 'user'])
            ->get();
    }

    /**
     * Active terms whose renewal window (the grace days before end_date) has opened.
     */
    public function findRenewalOpenUnnotified()
    {
        return $this->manuallyRenewed()
            ->where('status', 'active')
            ->whereNotNull('end_date')
            ->whereNull('renewal_open_reminder_sent_at')
            ->whereBetween('end_date', [Carbon::now(), Carbon::now()->addDays(Subscription::GRACE_DAYS)])
            ->with(['plan', 'pendingPlan', 'user'])
            ->get();
    }

    /**
     * Terms that ran out recently without the owner starting another one.
     *
     * Limited to the last few days so older lapses aren't all emailed at once.
     */
    public function findRecentlyExpiredUnnotified(int $withinDays)
    {
        return Subscription::where('status', 'expired')
            ->whereNull('expired_notice_sent_at')
            ->where('end_date', '>=', Carbon::now()->subDays($withinDays))
            ->whereDoesntHave('user.subscriptions', fn ($q) => $q->where('status', 'active'))
            ->with(['plan', 'pendingPlan', 'user'])
            ->get();
    }

    /**
     * Claims a notice for sending. Returns false if another run already claimed it.
     */
    public function claimNotice(Subscription $subscription, string $marker): bool
    {
        return Subscription::whereKey($subscription->sub_id)
            ->whereNull($marker)
            ->update([$marker => Carbon::now()]) === 1;
    }

    public function listSubscribers(int $perPage = 15)
    {
        return Subscription::with(['user', 'plan.features', 'latestPayment'])
            ->latest('created_at')
            ->paginate($perPage);
    }

    public function findHistoryByOwnerUuid(string $ownerUuid, int $perPage = 15)
    {
        return Subscription::whereHas('user', fn ($q) => $q->where('uuid', $ownerUuid))
            ->with(['plan.features', 'user'])
            ->latest('created_at')
            ->paginate($perPage);
    }

    public function cancelOtherActiveSubscriptions(int $userId, int $exceptSubId): void
    {
        Subscription::where('user_id', $userId)
            ->where('sub_id', '!=', $exceptSubId)
            ->where('status', 'active')
            ->update([
                'status'   => 'cancelled',
                'end_date' => Carbon::now(),
            ]);
    }

    public function cancelForSuspension(int $userId): void
    {
        Subscription::where('user_id', $userId)
            ->where('status', 'active')
            ->update([
                'status'   => 'cancelled',
                'end_date' => Carbon::now(),
            ]);
    }

    /**
     * Create a pending subscription tied to a subscription the gateway itself manages.
     */
    public function createPendingWithGatewaySubscription(int $userId, SubscriptionPlan $plan, string $gatewaySubscriptionId, string $billingCycle = 'monthly'): Subscription
    {
        return Subscription::create([
            'user_id'                 => $userId,
            'sub_plan_id'              => $plan->sub_plan_id,
            'start_date'               => null,
            'end_date'                 => null,
            'status'                   => 'pending',
            'billing_cycle'            => $billingCycle,
            'cancel_at_period_end'     => false,
            'gateway_subscription_id'  => $gatewaySubscriptionId,
        ]);
    }

    public function findByGatewaySubscriptionId(string $gatewaySubscriptionId): ?Subscription
    {
        return Subscription::where('gateway_subscription_id', $gatewaySubscriptionId)
            ->with(['plan', 'user'])
            ->first();
    }

    /**
     * First billing cycle confirmed by the gateway — subscription is now genuinely active.
     * The gateway dictates the period end here, unlike activate() which derives it locally.
     */
    public function activateFromGateway(Subscription $subscription, ?Carbon $nextBillingTime): Subscription
    {
        $subscription->update([
            'start_date' => $subscription->start_date ?? Carbon::now(),
            'end_date'   => $nextBillingTime,
            'status'     => 'active',
        ]);

        return $subscription->fresh(['plan', 'user']);
    }

    public function markPastDue(Subscription $subscription): Subscription
    {
        $subscription->update(['status' => 'past_due']);

        return $subscription->fresh(['plan', 'user']);
    }

    public function markCancelledByGateway(Subscription $subscription): Subscription
    {
        $subscription->update(['status' => 'cancelled']);

        return $subscription->fresh(['plan', 'user']);
    }
}