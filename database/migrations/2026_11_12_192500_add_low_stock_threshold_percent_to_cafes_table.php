<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('cafes', 'low_stock_threshold_percent')) {
            Schema::table('cafes', function (Blueprint $table) {
                $table->unsignedTinyInteger('low_stock_threshold_percent')
                    ->nullable()
                    ->after('cafe_name');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('cafes', 'low_stock_threshold_percent')) {
            Schema::table('cafes', function (Blueprint $table) {
                $table->dropColumn('low_stock_threshold_percent');
            });
        }
    }
};

