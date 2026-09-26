<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('subscriptions', function (Blueprint $table) {
            $table->timestamp('renewal_open_reminder_sent_at')->nullable()->after('expiration_reminder_sent_at');
            $table->timestamp('expired_notice_sent_at')->nullable()->after('renewal_open_reminder_sent_at');
        });
    }

    public function down(): void
    {
        Schema::table('subscriptions', function (Blueprint $table) {
            $table->dropColumn(['renewal_open_reminder_sent_at', 'expired_notice_sent_at']);
        });
    }
};
