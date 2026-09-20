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
            $table->dropForeign(['men_category_id']);
            $table->foreignId('men_category_id')->nullable(false)->change();
            $table->foreign('men_category_id')->references('men_category_id')->on('menu_categories')->onDelete('cascade');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('menu_items', function (Blueprint $table) {
            $table->dropForeign(['men_category_id']);
            $table->foreignId('men_category_id')->nullable()->change();
            $table->foreign('men_category_id')->references('men_category_id')->on('menu_categories')->onDelete('set null');
        });
    }
};
