<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * BrewSpot now bills through PayMongo only, so the gateway-specific column names are
 * renamed to neutral ones. These columns have already been renamed once (PayMongo →
 * PayPal, see 2026_08_26_210346); naming them after the role they play rather than the
 * vendor that happens to fill them avoids a third rename if the gateway changes again.
 *
 * The columns are kept rather than dropped: PayMongoWebhookController already handles
 * subscription.* events, so it needs somewhere to store a gateway-side subscription id
 * if PayMongo enables recurring billing on the account.
 */
return new class extends Migration
{
    /**
     * Old column name => new column name, keyed by table.
     */
    private const RENAMES = [
        'users' => [
            'paypal_payer_id' => 'gateway_customer_id',
        ],
        'subscription_plans' => [
            'paypal_plan_id'        => 'gateway_plan_id',
            'paypal_yearly_plan_id' => 'gateway_yearly_plan_id',
        ],
        'subscriptions' => [
            'paypal_subscription_id' => 'gateway_subscription_id',
        ],
    ];

    public function up(): void
    {
        $this->applyRenames(self::RENAMES);
    }

    public function down(): void
    {
        $reversed = [];

        foreach (self::RENAMES as $table => $columns) {
            $reversed[$table] = array_flip($columns);
        }

        $this->applyRenames($reversed);
    }

    /**
     * Rename each column only when the source exists and the target does not, so the
     * migration stays safe to re-run against a partially migrated database.
     */
    private function applyRenames(array $map): void
    {
        foreach ($map as $table => $columns) {
            if (! Schema::hasTable($table)) {
                continue;
            }

            Schema::table($table, function (Blueprint $blueprint) use ($table, $columns) {
                foreach ($columns as $from => $to) {
                    if (Schema::hasColumn($table, $from) && ! Schema::hasColumn($table, $to)) {
                        $blueprint->renameColumn($from, $to);
                    }
                }
            });
        }
    }
};
