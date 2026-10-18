<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Sizes of a menu item (Small, Large), each with its own price. An item with
 * no sizes sells at menu_items.base_price.
 *
 * A recipe row with men_size_id = null is used by every size; a row with a
 * size is used only when that size is sold.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('menu_item_sizes', function (Blueprint $table) {
            $table->id('men_size_id');
            $table->string('uuid')->unique();
            $table->foreignId('men_item_id')->constrained('menu_items', 'men_item_id')->onDelete('cascade');
            $table->string('name', 50);
            $table->decimal('price', 10, 2);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();
        });

        Schema::table('menu_recipes', function (Blueprint $table) {
            $table->foreignId('men_size_id')->nullable()->after('men_item_id')
                ->constrained('menu_item_sizes', 'men_size_id')->onDelete('cascade');
        });

        // An ingredient may now appear once per size. The new key is added
        // before the old one is dropped so the men_item_id foreign key always
        // has an index (MySQL refuses to drop the only one).
        Schema::table('menu_recipes', function (Blueprint $table) {
            $table->unique(['men_item_id', 'men_size_id', 'ingredient_id']);
        });

        Schema::table('menu_recipes', function (Blueprint $table) {
            $table->dropUnique(['men_item_id', 'ingredient_id']);
        });
    }

    public function down(): void
    {
        // Size-specific rows would break the old one-row-per-ingredient key.
        DB::table('menu_recipes')->whereNotNull('men_size_id')->delete();

        Schema::table('menu_recipes', function (Blueprint $table) {
            $table->unique(['men_item_id', 'ingredient_id']);
        });

        Schema::table('menu_recipes', function (Blueprint $table) {
            $table->dropUnique(['men_item_id', 'men_size_id', 'ingredient_id']);
            $table->dropForeign(['men_size_id']);
            $table->dropColumn('men_size_id');
        });

        Schema::dropIfExists('menu_item_sizes');
    }
};
