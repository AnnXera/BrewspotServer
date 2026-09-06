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
        Schema::table('subscription_plans', function (Blueprint $table) {
            $table->decimal('yearly_price', 10, 2)->default(0.00)->after('price');
        });

        Schema::table('subscriptions', function (Blueprint $table) {
            $table->string('billing_cycle')->default('monthly')->after('status'); // monthly, yearly
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('subscriptions', function (Blueprint $table) {
            $table->dropColumn('billing_cycle');
        });

        Schema::table('subscription_plans', function (Blueprint $table) {
            $table->dropColumn('yearly_price');
        });
    }
};
