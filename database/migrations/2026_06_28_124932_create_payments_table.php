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
        Schema::create('payments', function (Blueprint $table) {
            // Primary Key matches your payments_id design
            $table->id('payments_id'); 
            
            // Public-facing secure identifier
            $table->uuid('uuid')->unique();

            // Foreign Keys (assuming bigIntegers matching Laravel defaults)
            $table->foreignId('user_id')->constrained('users', 'user_id')->onDelete('cascade');
            
            // branch_id is nullable because SaaS Subscriptions won't have a branch
            $table->foreignId('branch_id')->nullable()->constrained('cafe_branches', 'branch_id')->onDelete('set null');

            /**
             * Laravel Polymorphic Helper
             * This automatically creates:
             * 1. payable_id (bigint)
             * 2. payable_type (varchar)
             * It also automatically creates a combined database index for speed.
             */
            $table->morphs('payable');

            // Financial amounts stored in cents/centavos (Integer)
            $table->integer('amount');
            
            // POS Cash auditing columns (nullable since they are skipped for PayMongo/online payments)
            $table->integer('amount_tendered')->nullable()->comment('Cash received from customer');
            $table->integer('amount_change')->nullable()->comment('Change returned to customer');

            // Payment tracking
            $table->string('payment_method_type'); // 'card', 'gcash', 'paymaya', 'qrph', 'cash'
            $table->string('gateway_transaction_id')->nullable()->unique(); // PayMongo Payment Intent ID (pi_...)
            $table->string('status')->default('pending'); // 'pending', 'succeeded', 'failed', 'refunded'

            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('payments');
    }
};
