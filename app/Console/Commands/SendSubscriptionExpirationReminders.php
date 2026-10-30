<?php

namespace App\Console\Commands;

use App\Services\SubscriptionReminderService;
use Illuminate\Console\Command;

class SendSubscriptionExpirationReminders extends Command
{
    protected $signature = 'subscriptions:send-expiration-reminders';

    protected $description = 'Email and notify owners whose subscription expires in 7, 3 or 1 day(s), or whose subscription just expired.';

    public function __construct(
        private readonly SubscriptionReminderService $service
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $expiring = $this->service->sendExpirationReminders();
        $expired  = $this->service->sendExpiredNotices();

        $this->info("Queued {$expiring} expiring and {$expired} expired notice(s).");

        return self::SUCCESS;
    }
}
