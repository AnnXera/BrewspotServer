<?php

namespace App\Console\Commands;

use App\Models\Subscription;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class ExpireSubscriptions extends Command
{
    protected $signature = 'subscriptions:expire';

    protected $description = 'Mark subscriptions as expired once their end_date has passed';

    public function handle(): int
    {
        $expired = Subscription::where('status', 'active')
            ->where('end_date', '<', now())
            ->get();

        foreach ($expired as $subscription) {
            $subscription->update(['status' => 'expired']);

            Log::channel('subscriptions')->info('Subscription expired.', [
                'subscription_uuid' => $subscription->uuid,
                'user_id'           => $subscription->user_id,
                'end_date'          => $subscription->end_date,
            ]);
        }

        $this->info("Expired {$expired->count()} subscription(s).");

        return self::SUCCESS;
    }
}