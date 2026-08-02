<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('category_branches', function (Blueprint $table) {
            $table->id('cat_branch_id');
            $table->string('uuid')->unique();
            $table->foreignId('branch_id')->constrained('cafe_branches', 'branch_id')->onDelete('cascade');
            $table->foreignId('men_category_id')->constrained('menu_categories', 'men_category_id')->onDelete('cascade');
            $table->boolean('is_available')->default(true);
            $table->timestamps();

            $table->unique(['branch_id', 'men_category_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('category_branches');
    }
};