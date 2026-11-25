<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * PayMongo's payment.* webhooks for e-wallet payments made inside a checkout carry no metadata,
 * only the payment intent the checkout session created. Storing it lets those events be matched
 * back to the checkout (so a failed GCash payment shows as failed, not pending).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->string('gateway_payment_intent_id')->nullable()->index()->after('gateway_transaction_id');
        });
    }

    public function down(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->dropIndex(['gateway_payment_intent_id']);
            $table->dropColumn('gateway_payment_intent_id');
        });
    }
};
