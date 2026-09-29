<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('staff_schedules', function (Blueprint $table) {
            $table->id('schedule_id');
            $table->uuid('uuid')->unique();

            // Belongs to the branch assignment, so someone working at two
            // branches has a separate weekly schedule for each.
            $table->foreignId('staff_id')->constrained('cafe_staff', 'staff_id')->onDelete('cascade');

            // 0 = Sunday ... 6 = Saturday (same order as the UI).
            $table->unsignedTinyInteger('day_of_week');
            $table->boolean('is_day_off')->default(false);
            $table->time('start_time')->nullable();
            $table->time('end_time')->nullable();

            $table->timestamps();

            $table->unique(['staff_id', 'day_of_week']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('staff_schedules');
    }
};
