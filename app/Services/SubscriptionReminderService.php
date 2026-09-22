<?php

namespace App\Services;

use App\Contracts\MailAdapterInterface;
use App\Mail\SubscriptionExpiringMail;
use App\Models\Subscription;
use App\Repository\SubscriptionRepository;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

class SubscriptionReminderService
{
    public function __construct(
        private readonly SubscriptionRepository $subscriptionRepo,
        private readonly MailAdapterInterface $mailer
    ) {}

    public function sendExpirationReminders(int $withinDays = 3): int
    {
        $subscriptions = $this->subscriptionRepo->findExpiringWithinDays($withinDays);
        $count = 0;

        foreach ($subscriptions as $subscription) {
            $owner = $subscription->user;

            if (! $owner) {
                continue;
            }

            $daysRemaining = max(0, (int) Carbon::now()->startOfDay()->diffInDays($subscription->end_date->startOfDay(), false));

            $this->mailer->sendMailable($owner->email, new SubscriptionExpiringMail(
                ownerName: $owner->firstname ?? $owner->username ?? 'there',
                planName: $subscription->plan->sub_name,
                endDate: $subscription->end_date->format('F j, Y'),
                daysRemaining: $daysRemaining,
                renewUrl: $this->buildRenewUrl($subscription),
                renewPlanName: ($subscription->pendingPlan ?? $subscription->plan)->sub_name,
                renewalOpensOn: $subscription->renewalOpensAt()?->format('F j, Y'),
            ));

            $this->subscriptionRepo->markReminderSent($subscription);

            Log::channel('owner')->info('Subscription expiration reminder sent.', [
                'owner_uuid'         => $owner->uuid,
                'subscription_uuid'  => $subscription->uuid,
                'end_date'           => $subscription->end_date->toDateString(),
            ]);

            $count++;
        }

        return $count;
    }

    /**
     * Deep link to the owner's subscription page with the current plan preselected.
     *
     * The owner signs in there and pays through the existing checkout modal, so renewal
     * reuses the same gateway path as a first-time purchase — no payment link is embedded
     * in the email itself, and nothing is chargeable without an authenticated session.
     */
    private function buildRenewUrl(Subscription $subscription): string
    {
        // A booked plan change takes effect by being what the owner renews into, so the
        // link offers the pending plan when one is set rather than the expiring one.
        $plan  = $subscription->pendingPlan ?? $subscription->plan;
        $cycle = $subscription->pending_billing_cycle ?? $subscription->billing_cycle ?? 'monthly';

        return rtrim(config('app.frontend_url'), '/') . '/owner/subscription?' . http_build_query([
            'renew' => $plan->uuid,
            'cycle' => $cycle,
        ]);
    }
}