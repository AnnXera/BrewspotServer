<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Moves the per-notice "sent" columns off subscriptions into one row per notice sent.
 *
 * The old 3-day reminder becomes expiring_3d, and the old renewal-open notice becomes
 * expiring_1d, since the 1-day reminder now also announces that renewal is open.
 */
return new class extends Migration
{
    private const COLUMN_KINDS = [
        'expiration_reminder_sent_at'   => 'expiring_3d',
        'renewal_open_reminder_sent_at' => 'expiring_1d',
        'expired_notice_sent_at'        => 'expired',
    ];

    public function up(): void
    {
        Schema::create('subscription_notices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('sub_id')->constrained('subscriptions', 'sub_id')->cascadeOnDelete();
            $table->string('kind');
            $table->timestamp('sent_at');
            $table->timestamps();

            $table->unique(['sub_id', 'kind']);
        });

        $now = Carbon::now();

        foreach (self::COLUMN_KINDS as $column => $kind) {
            DB::table('subscriptions')->whereNotNull($column)->orderBy('sub_id')
                ->select(['sub_id', $column])
                ->chunk(500, function ($rows) use ($column, $kind, $now) {
                    DB::table('subscription_notices')->insertOrIgnore($rows->map(fn ($row) => [
                        'sub_id'     => $row->sub_id,
                        'kind'       => $kind,
                        'sent_at'    => $row->{$column},
                        'created_at' => $now,
                        'updated_at' => $now,
                    ])->all());
                });
        }

        Schema::table('subscriptions', function (Blueprint $table) {
            $table->dropColumn(array_keys(self::COLUMN_KINDS));
        });
    }

    public function down(): void
    {
        Schema::table('subscriptions', function (Blueprint $table) {
            $table->timestamp('expiration_reminder_sent_at')->nullable()->after('cancel_at_period_end');
            $table->timestamp('renewal_open_reminder_sent_at')->nullable()->after('expiration_reminder_sent_at');
            $table->timestamp('expired_notice_sent_at')->nullable()->after('renewal_open_reminder_sent_at');
        });

        foreach (self::COLUMN_KINDS as $column => $kind) {
            DB::table('subscription_notices')->where('kind', $kind)->orderBy('id')
                ->chunk(500, function ($rows) use ($column) {
                    foreach ($rows as $row) {
                        DB::table('subscriptions')->where('sub_id', $row->sub_id)->update([$column => $row->sent_at]);
                    }
                });
        }

        Schema::dropIfExists('subscription_notices');
    }
};
