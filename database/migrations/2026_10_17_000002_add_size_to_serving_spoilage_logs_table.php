<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A spoilage entry remembers the size thrown away and its price at the time,
 * so the spoilage cost doesn't change when the menu is edited later.
 * unit_value = size price, or the item's base price when it has no sizes.
 * Add-ons are not recorded.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('serving_spoilage_logs', function (Blueprint $table) {
            if (!Schema::hasColumn('serving_spoilage_logs', 'men_size_id')) {
                $table->foreignId('men_size_id')->nullable()->after('servings_id')
                    ->constrained('menu_item_sizes', 'men_size_id')->nullOnDelete();
            }
            if (!Schema::hasColumn('serving_spoilage_logs', 'size_name')) {
                $table->string('size_name', 50)->nullable()->after('men_size_id');
            }
            if (!Schema::hasColumn('serving_spoilage_logs', 'unit_value')) {
                $table->decimal('unit_value', 10, 2)->nullable()->after('quantity'); // null on entries logged before this
            }
        });
    }

    public function down(): void
    {
        Schema::table('serving_spoilage_logs', function (Blueprint $table) {
            $table->dropForeign(['men_size_id']);
            $table->dropColumn(['men_size_id', 'size_name', 'unit_value']);
        });
    }
};
