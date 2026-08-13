<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cafe_branches', function (Blueprint $table) {
            $table->dropUnique(['cafe_email']);
            $table->index('cafe_email');
        });
    }

    public function down(): void
    {
        Schema::table('cafe_branches', function (Blueprint $table) {
            $table->dropIndex(['cafe_email']);
            $table->unique('cafe_email');
        });
    }
};