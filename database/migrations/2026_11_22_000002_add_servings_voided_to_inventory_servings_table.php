<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Servings from sales that were voided at the register. Like refunded ones they
     * were made, so they stay used up: voiding does not put them back on sale.
     */
    public function up(): void
    {
        Schema::table('inventory_servings', function (Blueprint $table) {
            $table->unsignedInteger('servings_voided')->default(0)->after('servings_refunded');
        });
    }

    public function down(): void
    {
        Schema::table('inventory_servings', function (Blueprint $table) {
            $table->dropColumn('servings_voided');
        });
    }
};
