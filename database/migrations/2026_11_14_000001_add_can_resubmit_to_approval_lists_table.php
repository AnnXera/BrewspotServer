<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Whether a rejected applicant may edit and resubmit the application.
     * False marks a final rejection (ineligible or suspected fraud).
     */
    public function up(): void
    {
        Schema::table('approval_lists', function (Blueprint $table) {
            $table->boolean('can_resubmit')->default(true)->after('reason');
        });
    }

    public function down(): void
    {
        Schema::table('approval_lists', function (Blueprint $table) {
            $table->dropColumn('can_resubmit');
        });
    }
};
