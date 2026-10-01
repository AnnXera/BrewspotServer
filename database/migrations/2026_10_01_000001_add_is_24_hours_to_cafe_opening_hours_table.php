<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cafe_opening_hours', function (Blueprint $table) {
            $table->boolean('is_24_hours')->default(false)->after('is_closed');
        });
    }

    public function down(): void
    {
        Schema::table('cafe_opening_hours', function (Blueprint $table) {
            $table->dropColumn('is_24_hours');
        });
    }
};
