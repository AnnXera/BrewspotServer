<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Spoilage logged on the register is approved by a manager or the owner with their PIN.
     * Null on entries logged before approval was required, and once that account is deleted.
     */
    public function up(): void
    {
        Schema::table('serving_spoilage_logs', function (Blueprint $table) {
            $table->foreignId('approved_by')->nullable()->after('device_id')->constrained('users', 'user_id')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('serving_spoilage_logs', function (Blueprint $table) {
            $table->dropConstrainedForeignId('approved_by');
        });
    }
};
