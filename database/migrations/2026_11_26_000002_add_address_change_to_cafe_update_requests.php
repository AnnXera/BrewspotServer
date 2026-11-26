<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Second cafe update type, 'address_change': one branch moves, so it sends its new
 * address plus the updated BIR COR (Form 2303) showing it. Only that branch is
 * covered, so the request points at it. The name columns only apply to
 * 'name_change', so they become nullable.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cafe_update_requests', function (Blueprint $table) {
            $table->foreignId('branch_id')->nullable()->after('cafe_id')
                ->constrained('cafe_branches', 'branch_id')->cascadeOnDelete(); // address_change only
            $table->text('old_address')->nullable()->after('new_name');
            $table->text('new_address')->nullable()->after('old_address');

            $table->string('old_name')->nullable()->change();
            $table->string('new_name')->nullable()->change();

            $table->index(['branch_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::table('cafe_update_requests', function (Blueprint $table) {
            $table->dropIndex(['branch_id', 'status']);
            $table->dropConstrainedForeignId('branch_id');
            $table->dropColumn(['old_address', 'new_address']);
        });
    }
};
