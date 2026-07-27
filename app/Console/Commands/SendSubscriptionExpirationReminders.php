<?php

namespace App\Console\Commands;

use App\Services\SubscriptionReminderService;
use Illuminate\Console\Command;

class SendSubscriptionExpirationReminders extends Command
{
    protected $signature = 'subscriptions:send-expiration-reminders';

    protected $description = 'Send email reminders to owners whose active subscription expires within 3 days.';

    public function __construct(
        private readonly SubscriptionReminderService $service
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $count = $this->service->sendExpirationReminders();

        $this->info("Sent {$count} subscription expiration reminder(s).");

        return self::SUCCESS;
    }
}