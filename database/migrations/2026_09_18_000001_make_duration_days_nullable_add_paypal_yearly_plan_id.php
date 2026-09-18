<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Make duration_days nullable (only used for trial plans now)
     * and add paypal_yearly_plan_id for yearly billing cycle support.
     */
    public function up(): void
    {
        Schema::table('subscription_plans', function (Blueprint $table) {
            $table->integer('duration_days')->nullable()->default(null)->change();
            $table->string('paypal_yearly_plan_id')->nullable()->after('paypal_plan_id');
        });
    }

    public function down(): void
    {
        Schema::table('subscription_plans', function (Blueprint $table) {
            $table->dropColumn('paypal_yearly_plan_id');
            $table->integer('duration_days')->default(0)->change();
        });
    }
};
