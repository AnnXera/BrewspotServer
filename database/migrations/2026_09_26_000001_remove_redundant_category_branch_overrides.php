<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * category_branches rows are now exceptions only: a row exists while a branch differs from
 * its category's default is_available. Rows written before that rule (toggled back to the
 * default) are removed so those branches follow the category default again.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('category_branches')
            ->whereExists(function ($query) {
                $query->select(DB::raw(1))
                    ->from('menu_categories')
                    ->whereColumn('menu_categories.men_category_id', 'category_branches.men_category_id')
                    ->whereColumn('menu_categories.is_available', 'category_branches.is_available');
            })
            ->delete();
    }

    /**
     * Not reversible: the removed rows matched the category default, so effective
     * availability is unchanged and there is nothing to restore.
     */
    public function down(): void
    {
        //
    }
};
