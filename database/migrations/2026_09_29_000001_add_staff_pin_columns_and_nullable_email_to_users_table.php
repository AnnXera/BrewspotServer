<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Cashiers may not have an email (they sign in through the POS with a PIN),
     * so email becomes nullable. The unique index stays — NULLs never collide.
     *
     * The PIN lives on the user (one PIN per person, across every branch they
     * work at). It is used to unlock a registered POS device and, for managers,
     * to approve voids/refunds.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('email')->nullable()->change();

            $table->string('pin_hash')->nullable()->after('password_hash');
            $table->unsignedTinyInteger('pin_failed_attempts')->default(0)->after('pin_hash');
            // Set when too many wrong PINs are entered; cleared only by an owner/manager PIN reset.
            $table->timestamp('pin_locked_at')->nullable()->after('pin_failed_attempts');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['pin_hash', 'pin_failed_attempts', 'pin_locked_at']);
            $table->string('email')->nullable(false)->change();
        });
    }
};
