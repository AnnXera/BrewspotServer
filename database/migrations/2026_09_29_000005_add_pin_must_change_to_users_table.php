<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A manager's PIN approves voids/refunds, so only they should know it.
     * When the owner sets or resets it, it's temporary: the manager must
     * choose a new one before the PIN can be used for anything.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->boolean('pin_must_change')->default(false)->after('pin_locked_at');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('pin_must_change');
        });
    }
};
