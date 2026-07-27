<?php

namespace App\Services;

use App\Contracts\MailAdapterInterface;
use App\Mail\SubscriptionExpiringMail;
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
}