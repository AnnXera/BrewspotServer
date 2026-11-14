<?php

namespace Tests\Feature;

use App\Models\ActivityLog;

/**
 * Booking, editing and cancelling a reservation from the owner or manager dashboard is
 * written to the activity log (category `reservations`); the register's status changes are not.
 */
class ReservationActivityLogTest extends FloorPlanTestCase
{
    private function logs()
    {
        return ActivityLog::where('category', ActivityLog::CATEGORY_RESERVATIONS)->orderBy('activity_log_id')->get();
    }

    public function test_manager_booking_is_logged(): void
    {
        $table = $this->makeTable($this->makePlan($this->mainBranch), 'T1');

        $uuid = $this->api('POST', $this->base('manager') . '/reservations', $this->tokenFor($this->manager), $this->booking($table, ['party_size' => 3]))
            ->assertCreated()->json('reservation.uuid');

        $log = $this->logs()->sole();
        $this->assertSame(ActivityLog::RESERVATION_CREATED, $log->action);
        $this->assertSame('Booked T1 (Main Floor) for Juan Dela Cruz, party of 3, on Oct 6, 10:00 AM to 11:30 AM', $log->description);
        $this->assertSame($this->manager->user_id, $log->user_id);
        $this->assertSame('Manager', $log->actor_role);
        $this->assertSame($this->mainBranch->branch_id, $log->branch_id);
        $this->assertSame($uuid, $log->meta['reservation']['uuid']);
    }

    public function test_owner_edit_names_what_changed_without_contact_values(): void
    {
        $table = $this->makeTable($this->makePlan($this->otherBranch), 'T1');
        $base  = $this->base('owner', $this->otherBranch) . '/reservations';
        $token = $this->tokenFor($this->owner);

        $uuid = $this->api('POST', $base, $token, $this->booking($table))->assertCreated()->json('reservation.uuid');

        $this->api('PATCH', "$base/$uuid", $token, [
            'party_size'      => 4,
            'customer_phone'  => '0918 765 4321',
            'reservation_end' => '2026-10-06 12:00:00',
        ])->assertOk();

        // Saving without changes adds nothing.
        $this->api('PATCH', "$base/$uuid", $token, ['party_size' => 4])->assertOk();

        $logs = $this->logs();
        $this->assertSame([ActivityLog::RESERVATION_CREATED, ActivityLog::RESERVATION_UPDATED], $logs->pluck('action')->all());

        $edit = $logs[1];
        $this->assertSame('Edited the reservation for Juan Dela Cruz on Oct 6, 10:00 AM to 12:00 PM (phone, party size, time)', $edit->description);
        $this->assertSame('Cafe Owner', $edit->actor_role);
        $this->assertSame([
            ['field' => 'Phone'],
            ['field' => 'Party size', 'before' => '2', 'after' => '4'],
            ['field' => 'Time', 'before' => 'Oct 6, 10:00 AM to 11:30 AM', 'after' => 'Oct 6, 10:00 AM to 12:00 PM'],
        ], $edit->meta['changes']);
        $this->assertStringNotContainsString('0918', json_encode($edit->meta));
    }

    public function test_cancelling_is_logged_but_other_status_changes_and_the_register_are_not(): void
    {
        $plan    = $this->makePlan($this->mainBranch);
        $first   = $this->makeReservation($this->makeTable($plan, 'T1'), '2026-10-06 10:00:00', '2026-10-06 11:00:00');
        $second  = $this->makeReservation($this->makeTable($plan, 'T2'), '2026-10-06 10:00:00', '2026-10-06 11:00:00');
        $manager = $this->tokenFor($this->manager);

        $this->api('POST', $this->base('manager') . "/reservations/{$first->uuid}/status", $manager, ['status' => 'cancelled'])->assertOk();

        $this->api('PATCH', "/api/pos/device/reservations/{$second->uuid}/status", $this->device(), ['status' => 'cancelled'])->assertOk();

        $log = $this->logs()->sole();
        $this->assertSame(ActivityLog::RESERVATION_CANCELLED, $log->action);
        $this->assertSame('Cancelled the reservation for Walk In at T1 (Main Floor) on Oct 6, 10:00 AM to 11:00 AM', $log->description);
        $this->assertSame($first->uuid, $log->meta['reservation']['uuid']);
    }

    public function test_reservation_entries_show_on_the_owner_and_manager_activity_logs(): void
    {
        $table = $this->makeTable($this->makePlan($this->mainBranch), 'T1');
        $token = $this->tokenFor($this->manager);

        $uuid = $this->api('POST', $this->base('manager') . '/reservations', $token, $this->booking($table))->assertCreated()->json('reservation.uuid');
        $this->api('POST', $this->base('manager') . "/reservations/{$uuid}/status", $token, ['status' => 'cancelled'])->assertOk();

        $this->api('GET', '/api/owner/activity-logs?from=2026-10-05&to=2026-10-05&category=reservations', $this->tokenFor($this->owner))
            ->assertOk()
            ->assertJsonPath('counts.reservations', 2)
            ->assertJsonCount(2, 'logs')
            ->assertJsonPath('logs.0.action', ActivityLog::RESERVATION_CANCELLED);

        $this->api('GET', $this->base('manager') . '/activity-logs?from=2026-10-05&to=2026-10-05&category=reservations', $token)
            ->assertOk()
            ->assertJsonCount(2, 'logs');
    }
}
