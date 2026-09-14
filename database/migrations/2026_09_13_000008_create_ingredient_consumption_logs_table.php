<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('ingredient_consumption_logs', function (Blueprint $table) {
            $table->id('consumption_id');
            $table->string('uuid')->unique();
            $table->foreignId('transaction_item_id')->constrained('transaction_items', 'transaction_item_id')->onDelete('cascade');
            $table->string('ingredient_name');
            $table->decimal('quantity_consumed', 10, 2);
            $table->string('unit');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('ingredient_consumption_logs');
    }
};
