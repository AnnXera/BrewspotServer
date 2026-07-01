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

    /**
     * The owner's currently active subscription, if any.
     */
    public function findCurrentByUserId(int $userId): ?Subscription
    {
        return Subscription::where('user_id', $userId)
            ->where('status', 'active')
            ->with('plan')
            ->latest('start_date')
            ->first();
    }

    /**
     * Full subscription history for this owner, newest first.
     */
    public function findHistoryByUserId(int $userId, int $perPage = 15)
    {
        return Subscription::where('user_id', $userId)
            ->with('plan')
            ->latest('start_date')
            ->paginate($perPage);
    }
}