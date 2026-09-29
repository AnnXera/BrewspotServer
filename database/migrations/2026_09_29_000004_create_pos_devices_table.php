<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pos_devices', function (Blueprint $table) {
            $table->id('device_id');
            $table->uuid('uuid')->unique();

            $table->foreignId('branch_id')->constrained('cafe_branches', 'branch_id')->onDelete('cascade');

            // e.g. "Front Counter", "Register 2"
            $table->string('name');

            // Owner or manager who registered this device.
            $table->foreignId('registered_by')->nullable()->constrained('users', 'user_id')->nullOnDelete();

            // Staff member currently unlocked on this device (tap name + PIN).
            // Null means the device is on the lock screen.
            $table->foreignId('active_staff_id')->nullable()->constrained('cafe_staff', 'staff_id')->nullOnDelete();
            $table->timestamp('active_since')->nullable();

            $table->timestamp('last_used_at')->nullable();

            // Removing a device revokes its token; the row is kept for history.
            $table->timestamp('revoked_at')->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pos_devices');
    }
};
