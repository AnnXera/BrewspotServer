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
        Schema::create('subscription_plans', function (Blueprint $table) {
            $table->id('sub_plan_id');
            $table->string('uuid')->unique();
            $table->string('sub_name');
            $table->decimal('price', 10, 2); // 00000000.00 format for currency
            $table->integer('max_branches');
            $table->integer('duration_days')->default(0);
            $table->text('description')->nullable();
            $table->timestamps();
        });

        Schema::create('subscriptions', function (Blueprint $table) {
            $table->id('sub_id');
            $table->string('uuid')->unique();
            
            // Foreign keys pointing to users and subscription plans
            $table->foreignId('user_id')->constrained('users', 'user_id')->onDelete('cascade');
            $table->foreignId('sub_plan_id')->constrained('subscription_plans', 'sub_plan_id')->onDelete('cascade');
            
            $table->timestamp('start_date')->nullable();
            $table->timestamp('end_date')->nullable();
            $table->string('status')->default('pending'); // pending, active, cancelled, expired
            $table->boolean('cancel_at_period_end')->default(false);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('subscriptions');
        Schema::dropIfExists('subscription_plans');
    }
};
