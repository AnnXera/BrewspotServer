<?php

namespace App\Console\Commands;

use App\Services\SubscriptionCheckoutService;
use Illuminate\Console\Command;

class CloseStalePendingCheckouts extends Command
{
    protected $signature = 'subscriptions:close-stale-checkouts {--hours=24 : Hours a checkout may stay unfinished before it is closed}';

    protected $description = 'Mark subscription checkouts that were never finished (tab closed, payment abandoned) as failed';

    public function handle(SubscriptionCheckoutService $checkouts): int
    {
        $hours = max(1, (int) $this->option('hours'));

        $closed = $checkouts->closeStalePendingCheckouts($hours);

        $this->info("Closed {$closed} stale checkout(s) older than {$hours} hour(s).");

        return self::SUCCESS;
    }
}
