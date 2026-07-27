<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('menu_categories', function (Blueprint $table) {
            $table->id('men_category_id');
            $table->string('uuid')->unique();
            $table->foreignId('cafe_id')->constrained('cafes', 'cafe_id')->onDelete('cascade');
            $table->string('name');
            $table->timestamps();

            $table->unique(['cafe_id', 'name']); // same name allowed across different cafes, not within one
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('menu_categories');
    }
};