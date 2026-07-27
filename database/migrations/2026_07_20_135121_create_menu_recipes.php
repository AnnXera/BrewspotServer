<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('menu_recipes', function (Blueprint $table) {
            $table->id('men_recipe_id');
            $table->string('uuid')->unique();
            $table->foreignId('men_item_id')->constrained('menu_items', 'men_item_id')->onDelete('cascade');
            $table->string('ingredient_name');
            $table->decimal('quantity', 10, 2);
            $table->string('unit');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('menu_recipes');
    }
};