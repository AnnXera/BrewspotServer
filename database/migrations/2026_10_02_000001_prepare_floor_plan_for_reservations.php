<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Soft delete tables so reservation history survives a layout change.
        Schema::table('branch_tables', function (Blueprint $table) {
            $table->softDeletes();
            $table->index(['floorplan_id', 'table_name']);
        });

        Schema::table('reservations', function (Blueprint $table) {
            $table->dateTime('reservation_end')->nullable()->after('reservation_date');
            $table->index(['table_id', 'reservation_date']);
        });

        // Existing rows get the default duration so every reservation has an end.
        $minutes = (int) config('floor_plan.reservations.default_duration_minutes', 90);

        DB::table('reservations')->whereNull('reservation_end')->orderBy('reservation_id')->each(function ($row) use ($minutes) {
            DB::table('reservations')
                ->where('reservation_id', $row->reservation_id)
                ->update(['reservation_end' => \Illuminate\Support\Carbon::parse($row->reservation_date)->addMinutes($minutes)]);
        });
    }

    public function down(): void
    {
        Schema::table('reservations', function (Blueprint $table) {
            $table->dropIndex(['table_id', 'reservation_date']);
            $table->dropColumn('reservation_end');
        });

        Schema::table('branch_tables', function (Blueprint $table) {
            $table->dropIndex(['floorplan_id', 'table_name']);
            $table->dropSoftDeletes();
        });
    }
};
