<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Resizable elements (walls, counters, rugs). Null means "the client's default size for the asset".
        Schema::table('floor_plan_elements', function (Blueprint $table) {
            $table->decimal('width', 10, 2)->nullable()->after('y_location');
            $table->decimal('height', 10, 2)->nullable()->after('width');
        });
    }

    public function down(): void
    {
        Schema::table('floor_plan_elements', function (Blueprint $table) {
            $table->dropColumn(['width', 'height']);
        });
    }
};
