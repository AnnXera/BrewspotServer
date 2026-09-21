<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The previous migration renamed paypal_plan_id -> gateway_plan_id, which carried the old
 * PayPal billing plan ids ("P-7VA03541JG019960ANKXFADI") into columns that now describe a
 * PayMongo plan. Those ids mean nothing to PayMongo, so they are cleared rather than left
 * to look like real configuration.
 *
 * Only PayPal-shaped values are touched, so this stays safe to run after the columns have
 * been legitimately populated with PayMongo plan ids.
 */
return new class extends Migration
{
    private const COLUMNS = ['gateway_plan_id', 'gateway_yearly_plan_id'];

    public function up(): void
    {
        if (! Schema::hasTable('subscription_plans')) {
            return;
        }

        foreach (self::COLUMNS as $column) {
            if (! Schema::hasColumn('subscription_plans', $column)) {
                continue;
            }

            DB::table('subscription_plans')
                ->where(function ($query) use ($column) {
                    $query->where($column, 'like', 'P-%')
                        ->orWhere($column, '=', '');
                })
                ->update([$column => null]);
        }
    }

    /**
     * Not reversible: the cleared values were dead PayPal references with no meaning in a
     * PayMongo-only system, so there is nothing worth restoring.
     */
    public function down(): void
    {
        //
    }
};
