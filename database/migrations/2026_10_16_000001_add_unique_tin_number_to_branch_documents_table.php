<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A TIN can belong to only one active branch. Soft-deleted documents (archived
     * rejected applications, deleted branches) are left out so the TIN can be reused,
     * hence the unique index sits on a generated column that is NULL for them.
     */
    public function up(): void
    {
        $duplicates = DB::table('branch_documents')
            ->whereNull('deleted_at')
            ->whereNotNull('tin_number')
            ->groupBy('tin_number')
            ->havingRaw('COUNT(*) > 1')
            ->pluck('tin_number');

        if ($duplicates->isNotEmpty()) {
            throw new RuntimeException(
                'Cannot add unique TIN constraint, these TINs are used by more than one active branch: '
                . $duplicates->implode(', ') . '. Fix them in branch_documents first.'
            );
        }

        Schema::table('branch_documents', function (Blueprint $table) {
            $table->string('active_tin_number')
                ->nullable()
                ->virtualAs('CASE WHEN deleted_at IS NULL THEN tin_number END')
                ->after('tin_number');

            $table->unique('active_tin_number', 'branch_documents_active_tin_number_unique');
        });
    }

    public function down(): void
    {
        Schema::table('branch_documents', function (Blueprint $table) {
            $table->dropUnique('branch_documents_active_tin_number_unique');
            $table->dropColumn('active_tin_number');
        });
    }
};
