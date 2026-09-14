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
        Schema::create('floor_plan_elements', function (Blueprint $table) {
            $table->id('element_id');
            $table->string('uuid')->unique();
            $table->foreignId('floorplan_id')->constrained('floor_plans', 'floor_plan_id')->onDelete('cascade');
            $table->string('category');
            $table->string('asset_key')->nullable();
            $table->string('label')->nullable();
            $table->decimal('x_location', 10, 2);
            $table->decimal('y_location', 10, 2);
            $table->decimal('rotation', 8, 2)->default(0);
            $table->integer('z_index')->default(0);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('floor_plan_elements');
    }
};
