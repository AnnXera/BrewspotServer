<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('paymongo_customer_id')->nullable()->after('status');
        });

        Schema::table('subscription_plans', function (Blueprint $table) {
            $table->string('paymongo_plan_id')->nullable()->after('is_active');
        });

        Schema::table('subscriptions', function (Blueprint $table) {
            $table->string('paymongo_subscription_id')->nullable()->after('expiration_reminder_sent_at');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('paymongo_customer_id');
        });

        Schema::table('subscription_plans', function (Blueprint $table) {
            $table->dropColumn('paymongo_plan_id');
        });

        Schema::table('subscriptions', function (Blueprint $table) {
            $table->dropColumn('paymongo_subscription_id');
        });
    }
};