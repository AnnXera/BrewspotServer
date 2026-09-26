<?php

namespace App\Console\Commands;

use App\Services\SubscriptionReminderService;
use Illuminate\Console\Command;

class SendSubscriptionExpirationReminders extends Command
{
    protected $signature = 'subscriptions:send-expiration-reminders';

    protected $description = 'Email owners whose subscription expires within 3 days, whose renewal payment has opened, or whose subscription just expired.';

    public function __construct(
        private readonly SubscriptionReminderService $service
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $expiring    = $this->service->sendExpirationReminders();
        $renewalOpen = $this->service->sendRenewalOpenReminders();
        $expired     = $this->service->sendExpiredNotices();

        $this->info("Queued {$expiring} expiring, {$renewalOpen} renewal-open and {$expired} expired notice(s).");

        return self::SUCCESS;
    }
}