<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Audit log of what people did at a cafe (sign-ins, register unlocks, servings changes).
        // Rows are written once and never updated. Actor name and role are copied in so the
        // log still reads correctly after an account is renamed, changes role or is deleted.
        Schema::create('activity_logs', function (Blueprint $table) {
            $table->id('activity_log_id');
            $table->uuid('uuid')->unique();
            $table->foreignId('cafe_id')->constrained('cafes', 'cafe_id')->cascadeOnDelete();
            $table->foreignId('branch_id')->nullable()->constrained('cafe_branches', 'branch_id')->nullOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users', 'user_id')->nullOnDelete();
            $table->foreignId('device_id')->nullable()->constrained('pos_devices', 'device_id')->nullOnDelete();
            $table->string('actor_name', 150)->nullable();
            $table->string('actor_role', 30)->nullable();
            $table->string('category', 20); // auth | pos | servings
            $table->string('action', 40);   // e.g. auth.login, pos.unlock, serving.updated
            $table->string('description', 255);
            $table->json('meta')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['cafe_id', 'created_at']);
            $table->index(['branch_id', 'created_at']);
            $table->index(['user_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('activity_logs');
    }
};
