<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('menu_items', function (Blueprint $table) {
            $table->id('men_item_id');
            $table->string('uuid')->unique();
            $table->foreignId('men_category_id')->constrained('menu_categories', 'men_category_id')->onDelete('cascade');
            $table->string('menu_name');
            $table->text('description')->nullable();
            $table->decimal('base_price', 10, 2);
            $table->boolean('is_available')->default(true);
            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('menu_items');
    }
};