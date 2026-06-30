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
        Schema::create('approval_lists', function (Blueprint $table) {
            $table->id('approval_id');
            $table->string('uuid')->unique();
            $table->foreignId('user_id')->constrained('users', 'user_id')->onDelete('cascade');
            $table->foreignId('cafe_id')->nullable()->constrained('cafes', 'cafe_id')->onDelete('set null');
            $table->foreignId('branch_id')->nullable()->constrained('cafe_branches', 'branch_id')->onDelete('set null');
            $table->string('status')->default('pending_approval');
            $table->foreignId('reviewed_by')->nullable()->constrained('users', 'user_id')->onDelete('set null');
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('approval_lists');
    }
};
