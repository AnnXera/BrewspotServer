<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * For a short while, refunds of orders already made were logged as spoilage
     * with reason 'refunded'. They belong in servings_refunded: move each active
     * entry's quantity over and drop the entry. Voided entries never counted, so
     * they're only dropped.
     */
    public function up(): void
    {
        DB::transaction(function () {
            $logs = DB::table('serving_spoilage_logs')->where('reason', 'refunded')->lockForUpdate()->get();

            foreach ($logs as $log) {
                $serving = $log->voided_at === null
                    ? DB::table('inventory_servings')->where('servings_id', $log->servings_id)->lockForUpdate()->first()
                    : null;

                if ($serving) {
                    $qty = min((int) $log->quantity, (int) $serving->spoilage_qty);

                    DB::table('inventory_servings')
                        ->where('servings_id', $serving->servings_id)
                        ->update([
                            'spoilage_qty'      => $serving->spoilage_qty - $qty,
                            'servings_refunded' => $serving->servings_refunded + $qty,
                        ]);
                }

                DB::table('serving_spoilage_logs')->where('spoilage_log_id', $log->spoilage_log_id)->delete();
            }
        });
    }

    public function down(): void
    {
        // Not reversible: the entries were never meant to exist.
    }
};
