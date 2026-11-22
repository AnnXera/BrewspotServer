<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * "Max per drink" becomes optional: null means no limit of its own (the POS
     * still caps every add-on at MenuAddon::MAX_QUANTITY_LIMIT per drink).
     * Existing add-ons keep the limit they have.
     */
    public function up(): void
    {
        Schema::table('menu_addons', function (Blueprint $table) {
            $table->unsignedTinyInteger('max_quantity')->nullable()->default(null)->change();
        });
    }

    public function down(): void
    {
        // Add-ons without a limit go back to the old default of 1
        \Illuminate\Support\Facades\DB::table('menu_addons')->whereNull('max_quantity')->update(['max_quantity' => 1]);

        Schema::table('menu_addons', function (Blueprint $table) {
            $table->unsignedTinyInteger('max_quantity')->nullable(false)->default(1)->change();
        });
    }
};
