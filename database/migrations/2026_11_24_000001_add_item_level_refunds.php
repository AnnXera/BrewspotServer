<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Refunds can cover some items of a sale instead of all of it.
     *
     *  - transaction_event_items: which items (and how many) one refund returned, and for how much
     *  - transaction_items.refunded_quantity: running count refunded from the line
     *  - transactions.refunded_amount: running money refunded, so income is total_amount - refunded_amount
     *
     * Sales already refunded in full are filled in as fully refunded.
     */
    public function up(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            $table->decimal('refunded_amount', 10, 2)->default(0)->after('total_amount');
        });

        Schema::table('transaction_items', function (Blueprint $table) {
            $table->unsignedInteger('refunded_quantity')->default(0)->after('quantity');
        });

        Schema::create('transaction_event_items', function (Blueprint $table) {
            $table->id('transaction_event_item_id');
            $table->foreignId('transaction_event_id')->constrained('transaction_events', 'transaction_event_id')->cascadeOnDelete();
            $table->foreignId('transaction_item_id')->constrained('transaction_items', 'transaction_item_id')->cascadeOnDelete();
            $table->unsignedInteger('quantity');
            $table->decimal('amount', 10, 2); // money returned for these, after their share of the discount
            $table->timestamp('created_at')->useCurrent();
        });

        DB::table('transactions')
            ->where('status', 'refunded')
            ->update(['refunded_amount' => DB::raw('total_amount')]);

        DB::table('transaction_items')
            ->whereIn('transaction_id', DB::table('transactions')->where('status', 'refunded')->select('transaction_id'))
            ->update(['refunded_quantity' => DB::raw('quantity')]);
    }

    public function down(): void
    {
        Schema::dropIfExists('transaction_event_items');

        Schema::table('transaction_items', function (Blueprint $table) {
            $table->dropColumn('refunded_quantity');
        });

        Schema::table('transactions', function (Blueprint $table) {
            $table->dropColumn('refunded_amount');
        });
    }
};
