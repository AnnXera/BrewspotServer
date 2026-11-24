<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Full refunds recorded before items were tracked get their items written down, so
     * ingredient usage can tell made refunds (ingredients used) from refunds of orders
     * never made. Each line is taken as refunded in full, with its share of what was paid.
     *
     * Only sales whose refund has an event row (it holds the made / not made answer) are
     * filled in. Refunds older than that have no answer on record and stay out of
     * ingredient usage, as before.
     */
    public function up(): void
    {
        $refunds = DB::table('transaction_events as te')
            ->join('transactions as t', 't.transaction_id', '=', 'te.transaction_id')
            ->where('te.event', 'refunded')
            ->where('t.status', 'refunded')
            ->whereNotExists(fn ($q) => $q
                ->from('transaction_event_items as tei')
                ->join('transaction_events as te2', 'te2.transaction_event_id', '=', 'tei.transaction_event_id')
                ->whereColumn('te2.transaction_id', 't.transaction_id'))
            ->orderByDesc('te.transaction_event_id')
            ->get(['te.transaction_event_id', 't.transaction_id', 't.total_amount'])
            ->unique('transaction_id'); // one refund per sale: its latest

        foreach ($refunds as $refund) {
            $lines = DB::table('transaction_items')
                ->where('transaction_id', $refund->transaction_id)
                ->orderBy('transaction_item_id')
                ->get(['transaction_item_id', 'quantity', 'unit_price']);

            $subtotal = $lines->sum(fn ($l) => (float) $l->unit_price * (int) $l->quantity);
            $ratio    = $subtotal > 0 ? (float) $refund->total_amount / $subtotal : 0;
            $rows     = [];
            $given    = 0.0;

            foreach ($lines->values() as $i => $line) {
                $amount = $i === $lines->count() - 1
                    ? round((float) $refund->total_amount - $given, 2) // the last line takes what is left
                    : round((float) $line->unit_price * (int) $line->quantity * $ratio, 2);
                $given += $amount;

                $rows[] = [
                    'transaction_event_id' => $refund->transaction_event_id,
                    'transaction_item_id'  => $line->transaction_item_id,
                    'quantity'             => (int) $line->quantity,
                    'amount'               => $amount,
                    'created_at'           => now(),
                ];
            }

            if ($rows) {
                DB::table('transaction_event_items')->insert($rows);
            }
        }
    }

    public function down(): void
    {
        // Rows written here can't be told apart from real ones; the item-refund migration's down() drops the table.
    }
};
