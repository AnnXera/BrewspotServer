<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->renameColumn('paymongo_customer_id', 'paypal_payer_id');
        });

        Schema::table('subscription_plans', function (Blueprint $table) {
            $table->renameColumn('paymongo_plan_id', 'paypal_plan_id');
        });

        Schema::table('subscriptions', function (Blueprint $table) {
            $table->renameColumn('paymongo_subscription_id', 'paypal_subscription_id');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->renameColumn('paypal_payer_id', 'paymongo_customer_id');
        });

        Schema::table('subscription_plans', function (Blueprint $table) {
            $table->renameColumn('paypal_plan_id', 'paymongo_plan_id');
        });

        Schema::table('subscriptions', function (Blueprint $table) {
            $table->renameColumn('paypal_subscription_id', 'paymongo_subscription_id');
        });
    }
};