<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Reverts the nullable email from 2026_09_29_000001: every employee,
     * cashiers included, must have an email for contact purposes.
     */
    public function up(): void
    {
        $missing = DB::table('users')->whereNull('email')->count();

        if ($missing > 0) {
            throw new RuntimeException(
                "Cannot make users.email required: {$missing} user(s) have no email. Add their emails first."
            );
        }

        Schema::table('users', function (Blueprint $table) {
            $table->string('email')->nullable(false)->change();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('email')->nullable()->change();
        });
    }
};
