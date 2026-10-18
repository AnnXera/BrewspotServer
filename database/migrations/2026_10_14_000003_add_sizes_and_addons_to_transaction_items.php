<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A sold line remembers the size and add-ons chosen. Names and prices are
 * copied at the time of sale so later menu edits don't change old receipts.
 * transaction_items.unit_price = size (or base) price + add-ons, per drink.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('transaction_items', function (Blueprint $table) {
            $table->foreignId('men_size_id')->nullable()->after('men_item_id')
                ->constrained('menu_item_sizes', 'men_size_id')->nullOnDelete();
            $table->string('size_name', 50)->nullable()->after('men_size_id');
        });

        Schema::create('transaction_item_addons', function (Blueprint $table) {
            $table->id('transaction_item_addon_id');
            $table->foreignId('transaction_item_id')->constrained('transaction_items', 'transaction_item_id')->onDelete('cascade');
            $table->foreignId('addon_id')->nullable()->constrained('menu_addons', 'addon_id')->nullOnDelete();
            $table->string('addon_name', 100);
            $table->decimal('price', 10, 2);   // one serving of the add-on
            $table->unsignedTinyInteger('quantity'); // per drink
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('transaction_item_addons');

        Schema::table('transaction_items', function (Blueprint $table) {
            $table->dropForeign(['men_size_id']);
            $table->dropColumn(['men_size_id', 'size_name']);
        });
    }
};
