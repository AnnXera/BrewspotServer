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
        Schema::create('transactions', function (Blueprint $table) {
            $table->id('transaction_id');
            $table->string('uuid')->unique();
            $table->foreignId('branch_id')->constrained('cafe_branches', 'branch_id')->onDelete('cascade');
            $table->foreignId('staff_id')->nullable()->constrained('cafe_staff', 'staff_id')->onDelete('set null');
            $table->decimal('total_amount', 10, 2)->default(0);
            $table->text('walk_in_note')->nullable();
            $table->string('status')->default('completed');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('transactions');
    }
};
