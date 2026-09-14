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
        Schema::create('floor_plans', function (Blueprint $table) {
            $table->id('floor_plan_id');
            $table->string('uuid')->unique();
            $table->foreignId('branch_id')->constrained('cafe_branches', 'branch_id')->onDelete('cascade');
            $table->string('floorplan_name');
            $table->decimal('canvas_width', 10, 2);
            $table->decimal('canvas_height', 10, 2);
            $table->json('boundary_points')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('floor_plans');
    }
};
