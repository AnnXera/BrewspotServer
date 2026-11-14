<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // An earlier version of this migration pointed the foreign key at users.id (the key is
        // user_id). On MySQL that left an empty qr_codes table behind without the constraint and
        // the migration unrecorded, so clear that leftover before creating it properly.
        Schema::dropIfExists('qr_codes');

        Schema::create('qr_codes', function (Blueprint $table) {
            $table->id();
            $table->uuid('token')->unique();
            $table->foreignId('owner_id')->constrained('users', 'user_id')->onDelete('cascade');
            $table->string('url');
            $table->string('path')->nullable(); // optional if you store image later
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('qr_codes');
    }
};
