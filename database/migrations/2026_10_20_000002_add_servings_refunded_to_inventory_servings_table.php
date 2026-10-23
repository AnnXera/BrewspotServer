<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('inventory_servings', function (Blueprint $table) {
            // Servings made and served, then refunded. Off servings_sold (no income)
            // but still used up, so remaining = expected - sold - spoilage - refunded.
            $table->unsignedInteger('servings_refunded')->default(0)->after('spoilage_qty');
        });
    }

    public function down(): void
    {
        Schema::table('inventory_servings', function (Blueprint $table) {
            $table->dropColumn('servings_refunded');
        });
    }
};
