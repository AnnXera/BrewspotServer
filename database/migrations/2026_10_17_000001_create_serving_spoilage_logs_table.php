<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One row per spoilage entry logged on a register. inventory_servings.spoilage_qty
 * stays as the running total of the entries that are not voided.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('serving_spoilage_logs', function (Blueprint $table) {
            $table->id('spoilage_log_id');
            $table->string('uuid')->unique();
            $table->foreignId('servings_id')->constrained('inventory_servings', 'servings_id')->cascadeOnDelete();
            $table->foreignId('staff_id')->nullable()->constrained('cafe_staff', 'staff_id')->nullOnDelete(); // who logged it
            $table->foreignId('device_id')->nullable()->constrained('pos_devices', 'device_id')->nullOnDelete();
            $table->unsignedInteger('quantity');
            $table->string('reason', 30)->nullable();
            $table->timestamp('voided_at')->nullable();
            $table->foreignId('voided_by')->nullable()->constrained('users', 'user_id')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('serving_spoilage_logs');
    }
};
