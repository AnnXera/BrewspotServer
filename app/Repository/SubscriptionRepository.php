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
        return Subscription::create([
            'user_id'              => $userId,
            'sub_plan_id'          => $plan->sub_plan_id,
            'start_date'           => Carbon::now(),
            'end_date'             => Carbon::now()->addDays($plan->duration_days),
            'status'               => 'active',
            'cancel_at_period_end' => false,
        ]);
    }

    public function findCurrentByUserId(int $userId): ?Subscription
    {
        return Subscription::where('user_id', $userId)
            ->where('status', 'active')
            ->with('plan')
            ->latest('start_date')
            ->first();
    }

    public function findHistoryByUserId(int $userId, int $perPage = 15)
    {
        return Subscription::where('user_id', $userId)
            ->with('plan')
            ->latest('start_date')
            ->paginate($perPage);
    }

    public function hasAnyByUserId(int $userId): bool
    {
        return Subscription::where('user_id', $userId)->exists();
    }

    public function findByUuid(string $uuid): ?Subscription
    {
        return Subscription::where('uuid', $uuid)->with(['plan', 'user'])->first();
    }

    public function createPending(int $userId, SubscriptionPlan $plan): Subscription
    {
        return Subscription::create([
            'user_id'              => $userId,
            'sub_plan_id'          => $plan->sub_plan_id,
            'start_date'           => null,
            'end_date'             => null,
            'status'               => 'pending',
            'cancel_at_period_end' => false,
        ]);
    }

    public function activate(Subscription $subscription): Subscription
    {
        $subscription->loadMissing('plan');

        $subscription->update([
            'start_date' => Carbon::now(),
            'end_date'   => Carbon::now()->addDays($subscription->plan->duration_days),
            'status'     => 'active',
        ]);

        return $subscription->fresh(['plan', 'user']);
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

    public function findExpiringWithinDays(int $days)
    {
        return Subscription::where('status', 'active')
            ->whereNotNull('end_date')
            ->whereNull('expiration_reminder_sent_at')
            ->whereBetween('end_date', [Carbon::now(), Carbon::now()->addDays($days)])
            ->with(['plan', 'user'])
            ->get();
    }

    public function markReminderSent(Subscription $subscription): void
    {
        $subscription->update(['expiration_reminder_sent_at' => Carbon::now()]);
    }

    public function listSubscribers(int $perPage = 15)
    {
        return Subscription::with(['user', 'plan', 'latestPayment'])
            ->latest('created_at')
            ->paginate($perPage);
    }

    public function findHistoryByOwnerUuid(string $ownerUuid, int $perPage = 15)
    {
        return Subscription::whereHas('user', fn ($q) => $q->where('uuid', $ownerUuid))
            ->with(['plan', 'user'])
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
     * PayPal-native: create a pending subscription tied to a real PayPal subscription ID.
     */
    public function createPendingWithPayPal(int $userId, SubscriptionPlan $plan, string $paypalSubscriptionId): Subscription
    {
        return Subscription::create([
            'user_id'                 => $userId,
            'sub_plan_id'              => $plan->sub_plan_id,
            'start_date'               => null,
            'end_date'                 => null,
            'status'                   => 'pending',
            'cancel_at_period_end'     => false,
            'paypal_subscription_id'   => $paypalSubscriptionId,
        ]);
    }

    public function findByPayPalSubscriptionId(string $paypalSubscriptionId): ?Subscription
    {
        return Subscription::where('paypal_subscription_id', $paypalSubscriptionId)
            ->with(['plan', 'user'])
            ->first();
    }

    /**
     * First billing cycle activated — subscription is now genuinely active.
     */
    public function activateFromPayPal(Subscription $subscription, ?Carbon $nextBillingTime): Subscription
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

    public function markCancelledByPayPal(Subscription $subscription): Subscription
    {
        $subscription->update(['status' => 'cancelled']);

        return $subscription->fresh(['plan', 'user']);
    }
}