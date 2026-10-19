<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Bookings are saved confirmed now, and the screens no longer offer a Confirm step,
        // so older pending bookings would be stuck with nothing but Cancel.
        DB::table('reservations')
            ->where('status', 'pending')
            ->update(['status' => 'confirmed', 'updated_at' => now()]);

        Schema::table('reservations', function (Blueprint $table) {
            $table->string('status')->default('confirmed')->change();
        });
    }

    public function down(): void
    {
        // Only the default goes back: which bookings were pending isn't recorded.
        Schema::table('reservations', function (Blueprint $table) {
            $table->string('status')->default('pending')->change();
        });
    }
};
