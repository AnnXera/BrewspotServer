<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cafe_staff', function (Blueprint $table) {
            // Who added / terminated this assignment (owner or manager). Null for rows
            // from before this was recorded, or once that account is deleted.
            $table->foreignId('hired_by')->nullable()->after('hired_at')
                ->constrained('users', 'user_id')->nullOnDelete();
            $table->foreignId('terminated_by')->nullable()->after('terminated_at')
                ->constrained('users', 'user_id')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('cafe_staff', function (Blueprint $table) {
            $table->dropConstrainedForeignId('hired_by');
            $table->dropConstrainedForeignId('terminated_by');
        });
    }
};
