<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('menu_branches', function (Blueprint $table) {
            $table->id('men_branch_id');
            $table->string('uuid')->unique();
            $table->foreignId('branch_id')->constrained('cafe_branches', 'branch_id')->onDelete('cascade');
            $table->foreignId('men_item_id')->constrained('menu_items', 'men_item_id')->onDelete('cascade');
            $table->boolean('is_available')->default(true);
            $table->decimal('branch_price', 10, 2)->nullable(); // null = falls back to menu_items.base_price
            $table->timestamps();

            $table->unique(['branch_id', 'men_item_id']); // prevent duplicate override rows
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('menu_branches');
    }
};