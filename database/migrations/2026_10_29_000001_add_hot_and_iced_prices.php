<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * An item can be sold hot or iced at different prices. The usual price
 * (menu_items.base_price, or each size's price) is the hot price; iced_price
 * sits next to it. Temperature never changes the recipe: ingredients still
 * come from the size alone.
 *
 * A sold line remembers the temperature chosen ('hot' or 'iced').
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('menu_items', function (Blueprint $table) {
            $table->boolean('is_hot_and_iced')->default(false)->after('base_price');
            $table->decimal('iced_price', 10, 2)->nullable()->after('is_hot_and_iced'); // items without sizes
        });

        Schema::table('menu_item_sizes', function (Blueprint $table) {
            $table->decimal('iced_price', 10, 2)->nullable()->after('price');
        });

        Schema::table('transaction_items', function (Blueprint $table) {
            $table->string('temperature', 10)->nullable()->after('size_name');
        });
    }

    public function down(): void
    {
        Schema::table('transaction_items', function (Blueprint $table) {
            $table->dropColumn('temperature');
        });

        Schema::table('menu_item_sizes', function (Blueprint $table) {
            $table->dropColumn('iced_price');
        });

        Schema::table('menu_items', function (Blueprint $table) {
            $table->dropColumn(['is_hot_and_iced', 'iced_price']);
        });
    }
};
