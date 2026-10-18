<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Add-ons (Oreo crumbles +20) belong to the cafe and are offered on the menu
 * items linked through menu_item_addons. Each has its own recipe so selling
 * one deducts its ingredients.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('menu_addons')) {
            Schema::create('menu_addons', function (Blueprint $table) {
                $table->id('addon_id');
                $table->string('uuid')->unique();
                $table->foreignId('cafe_id')->constrained('cafes', 'cafe_id')->onDelete('cascade');
                $table->string('name', 100);
                $table->decimal('price', 10, 2);
                $table->unsignedTinyInteger('max_quantity')->default(1); // per drink
                $table->boolean('is_available')->default(true);
                $table->timestamps();
                $table->softDeletes();
            });
        }

        if (!Schema::hasTable('menu_addon_recipes')) {
            Schema::create('menu_addon_recipes', function (Blueprint $table) {
                $table->id('addon_recipe_id');
                $table->string('uuid')->unique();
                $table->foreignId('addon_id')->constrained('menu_addons', 'addon_id')->onDelete('cascade');
                $table->foreignId('ingredient_id')->constrained('ingredients', 'ingredient_id');
                $table->string('ingredient_name'); // mirrors ingredients.name
                $table->decimal('quantity', 10, 2);
                $table->string('unit');
                $table->timestamps();
            });
        }

        if (!Schema::hasTable('menu_item_addons')) {
            Schema::create('menu_item_addons', function (Blueprint $table) {
                $table->id();
                $table->foreignId('men_item_id')->constrained('menu_items', 'men_item_id')->onDelete('cascade');
                $table->foreignId('addon_id')->constrained('menu_addons', 'addon_id')->onDelete('cascade');
                $table->timestamps();

                $table->unique(['men_item_id', 'addon_id']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('menu_item_addons');
        Schema::dropIfExists('menu_addon_recipes');
        Schema::dropIfExists('menu_addons');
    }
};
