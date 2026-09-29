<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cafe_staff', function (Blueprint $table) {
            // Filled when employment_status becomes 'terminated'. Rows are never
            // hard-deleted so transactions keep pointing at who handled them.
            $table->timestamp('terminated_at')->nullable()->after('hired_at');
        });
    }

    public function down(): void
    {
        Schema::table('cafe_staff', function (Blueprint $table) {
            $table->dropColumn('terminated_at');
        });
    }
};
