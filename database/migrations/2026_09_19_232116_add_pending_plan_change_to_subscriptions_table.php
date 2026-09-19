<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('subscriptions', function (Blueprint $table) {
            // A plan change (upgrade/downgrade) that PayPal has accepted but that hasn't
            // been paid for yet. sub_plan_id/billing_cycle only get updated to these values
            // once the next PAYMENT.SALE.COMPLETED webhook confirms the charge, since PayPal
            // doesn't prorate and would otherwise let owners use the new plan for free.
            $table->foreignId('pending_sub_plan_id')
                ->nullable()
                ->after('sub_plan_id')
                ->constrained('subscription_plans', 'sub_plan_id')
                ->nullOnDelete();
            $table->string('pending_billing_cycle')->nullable()->after('billing_cycle');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('subscriptions', function (Blueprint $table) {
            $table->dropConstrainedForeignId('pending_sub_plan_id');
            $table->dropColumn('pending_billing_cycle');
        });
    }
};
