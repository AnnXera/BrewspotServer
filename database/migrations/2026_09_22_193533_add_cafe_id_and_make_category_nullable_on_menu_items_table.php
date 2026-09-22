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
        Schema::table('menu_items', function (Blueprint $table) {
            $table->foreignId('cafe_id')->nullable()->constrained('cafes', 'cafe_id')->onDelete('cascade');
        });

        \Illuminate\Support\Facades\DB::statement('
            UPDATE menu_items 
            JOIN menu_categories ON menu_items.men_category_id = menu_categories.men_category_id 
            SET menu_items.cafe_id = menu_categories.cafe_id
        ');

        Schema::table('menu_items', function (Blueprint $table) {
            $table->unsignedBigInteger('men_category_id')->nullable()->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('menu_items', function (Blueprint $table) {
            $table->unsignedBigInteger('men_category_id')->nullable(false)->change();
            $table->dropForeign(['cafe_id']);
            $table->dropColumn('cafe_id');
        });
    }
};
