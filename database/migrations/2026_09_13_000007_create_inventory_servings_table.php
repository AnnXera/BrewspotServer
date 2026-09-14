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
        Schema::create('inventory_servings', function (Blueprint $table) {
            $table->id('servings_id');
            $table->string('uuid')->unique();
            $table->foreignId('men_item_id')->constrained('menu_items', 'men_item_id')->onDelete('cascade');
            $table->foreignId('branch_id')->constrained('cafe_branches', 'branch_id')->onDelete('cascade');
            $table->date('date');
            $table->integer('expected_servings')->default(0);
            $table->integer('servings_sold')->default(0);
            $table->integer('spoilage_qty')->default(0);
            $table->boolean('is_sold_out')->default(false);
            $table->timestamps();

            $table->unique(['men_item_id', 'branch_id', 'date']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('inventory_servings');
    }
};
