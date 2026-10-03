<?php

namespace Tests\Feature;

use App\Models\CafeOpeningHour;
use App\Models\Feature;

/**
 * Reservations: booking rules (capacity, hours, overlap, custom end time),
 * status flow with its manual/seated/completed table effects, and access.
 */
class ReservationTest extends FloorPlanTestCase
{
    private function table(string $name = 'T1', int $capacity = 4, ?\App\Models\FloorPlan $plan = null): \App\Models\BranchTable
    {
        return $this->makeTable($plan ?? $this->makePlan($this->mainBranch), $name, $capacity);
    }

    private function url(string $path = ''): string
    {
        return $this->base('manager') . '/reservations' . $path;
    }

    // ── Create ───────────────────────────────────────────────────────────

    public function test_staff_can_create_a_reservation_with_a_custom_end_time(): void
    {
        $table = $this->table();

        $this->api('POST', $this->url(), $this->tokenFor($this->manager), $this->booking($table, ['reservation_end' => '2026-10-06 13:15:00', 'notes' => 'Birthday']))
            ->assertCreated()
            ->assertJsonPath('reservation.status', 'pending')
            ->assertJsonPath('reservation.table.table_name', 'T1')
            ->assertJsonPath('reservation.table.floor_plan_name', 'Main Floor')
            ->assertJsonPath('reservation.notes', 'Birthday')
            ->assertJsonPath('reservation.created_by', trim("{$this->manager->firstname} {$this->manager->lastname}"));

        $reservation = \App\Models\Reservation::first();
        $this->assertEquals(195, $reservation->reservation_date->diffInMinutes($reservation->reservation_end));
        $this->assertSame($this->manager->user_id, $reservation->created_by);
    }

    public function test_owner_can_create_a_confirmed_reservation(): void
    {
        $this->api('POST', $this->base('owner') . '/reservations', $this->tokenFor($this->owner), $this->booking($this->table(), ['status' => 'confirmed']))
            ->assertCreated()->assertJsonPath('reservation.status', 'confirmed');

        $this->api('POST', $this->base('owner') . '/reservations', $this->tokenFor($this->owner), $this->booking($this->table('T2'), ['status' => 'seated']))
            ->assertStatus(422)->assertJsonValidationErrors('status');
    }

    public function test_required_fields_and_end_after_start(): void
    {
        $this->api('POST', $this->url(), $this->tokenFor($this->manager), [])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['table_uuid', 'customer_name', 'customer_phone', 'party_size', 'reservation_date', 'reservation_end']);

        $this->api('POST', $this->url(), $this->tokenFor($this->manager), $this->booking($this->table(), ['reservation_end' => '2026-10-06 09:30:00']))
            ->assertStatus(422)->assertJsonValidationErrors('reservation_end');
    }

    public function test_duration_limits(): void
    {
        $table = $this->table();
        $token = $this->tokenFor($this->manager);

        $this->api('POST', $this->url(), $token, $this->booking($table, ['reservation_end' => '2026-10-06 10:05:00']))
            ->assertStatus(422)->assertJsonValidationErrors('reservation_end');

        config(['floor_plan.reservations.max_duration_minutes' => 120]);
        $this->api('POST', $this->url(), $token, $this->booking($table, ['reservation_end' => '2026-10-06 13:00:00']))
            ->assertStatus(422)->assertJsonValidationErrors('reservation_end');
    }

    public function test_party_must_fit_the_table(): void
    {
        $this->api('POST', $this->url(), $this->tokenFor($this->manager), $this->booking($this->table('T1', 2), ['party_size' => 3]))
            ->assertStatus(422)->assertJsonValidationErrors('party_size');
    }

    public function test_cannot_book_in_the_past_or_too_far_ahead(): void
    {
        $table = $this->table();
        $token = $this->tokenFor($this->manager);

        // "Now" is Monday 08:00.
        $this->api('POST', $this->url(), $token, $this->booking($table, ['reservation_date' => '2026-10-05 07:00:00', 'reservation_end' => '2026-10-05 08:30:00']))
            ->assertStatus(422)->assertJsonValidationErrors('reservation_date');

        $this->api('POST', $this->url(), $token, $this->booking($table, ['reservation_date' => '2027-06-01 10:00:00', 'reservation_end' => '2027-06-01 11:00:00']))
            ->assertStatus(422)->assertJsonValidationErrors('reservation_date');
    }

    public function test_must_be_inside_opening_hours(): void
    {
        $table = $this->table();
        $token = $this->tokenFor($this->manager);

        // Mon-Fri 09:00-17:00, Sat/Sun closed.
        foreach ([
            ['2026-10-06 08:00:00', '2026-10-06 09:30:00'], // opens late
            ['2026-10-06 16:00:00', '2026-10-06 17:30:00'], // closes early
            ['2026-10-10 10:00:00', '2026-10-10 11:00:00'], // Saturday
        ] as [$start, $end]) {
            $this->api('POST', $this->url(), $token, $this->booking($table, ['reservation_date' => $start, 'reservation_end' => $end]))
                ->assertStatus(422)->assertJsonValidationErrors('reservation_date');
        }

        // Exactly open-to-close is fine.
        $this->api('POST', $this->url(), $token, $this->booking($table, ['reservation_date' => '2026-10-06 09:00:00', 'reservation_end' => '2026-10-06 17:00:00']))
            ->assertCreated();
    }

    public function test_cafes_that_close_after_midnight_accept_late_bookings(): void
    {
        CafeOpeningHour::where('cafe_id', $this->cafe->cafe_id)->where('day_of_week', 'Tuesday')
            ->update(['open_time' => '18:00:00', 'close_time' => '02:00:00']);

        $table = $this->table();
        $token = $this->tokenFor($this->manager);

        // Tuesday 23:00 -> Wednesday 01:00 sits inside Tuesday's late hours.
        $this->api('POST', $this->url(), $token, $this->booking($table, ['reservation_date' => '2026-10-06 23:00:00', 'reservation_end' => '2026-10-07 01:00:00']))
            ->assertCreated();

        // Wednesday 00:30 is still Tuesday's hours...
        $this->api('POST', $this->url(), $token, $this->booking($this->table('T2'), ['reservation_date' => '2026-10-07 00:30:00', 'reservation_end' => '2026-10-07 01:30:00']))
            ->assertCreated();

        // ...but running past 02:00 is not (Wednesday only opens at 09:00).
        $this->api('POST', $this->url(), $token, $this->booking($this->table('T3'), ['reservation_date' => '2026-10-07 01:00:00', 'reservation_end' => '2026-10-07 03:00:00']))
            ->assertStatus(422)->assertJsonValidationErrors('reservation_date');
    }

    public function test_twenty_four_hour_days_accept_any_time(): void
    {
        CafeOpeningHour::where('cafe_id', $this->cafe->cafe_id)->where('day_of_week', 'Tuesday')
            ->update(['is_24_hours' => true]);

        $this->api('POST', $this->url(), $this->tokenFor($this->manager), $this->booking($this->table(), ['reservation_date' => '2026-10-06 03:00:00', 'reservation_end' => '2026-10-06 04:00:00']))
            ->assertCreated();
    }

    // ── Overlap ──────────────────────────────────────────────────────────

    public function test_a_table_cannot_be_double_booked_but_back_to_back_is_fine(): void
    {
        $table = $this->table();
        $token = $this->tokenFor($this->manager);

        $this->api('POST', $this->url(), $token, $this->booking($table))->assertCreated(); // 10:00-11:30

        $this->api('POST', $this->url(), $token, $this->booking($table, ['reservation_date' => '2026-10-06 11:00:00', 'reservation_end' => '2026-10-06 12:00:00']))
            ->assertStatus(409);
        $this->api('POST', $this->url(), $token, $this->booking($table, ['reservation_date' => '2026-10-06 09:00:00', 'reservation_end' => '2026-10-06 10:30:00']))
            ->assertStatus(409);
        $this->api('POST', $this->url(), $token, $this->booking($table, ['reservation_date' => '2026-10-06 10:30:00', 'reservation_end' => '2026-10-06 11:00:00']))
            ->assertStatus(409); // fully inside

        // Back to back on both sides.
        $this->api('POST', $this->url(), $token, $this->booking($table, ['reservation_date' => '2026-10-06 11:30:00', 'reservation_end' => '2026-10-06 12:30:00']))->assertCreated();
        $this->api('POST', $this->url(), $token, $this->booking($table, ['reservation_date' => '2026-10-06 09:00:00', 'reservation_end' => '2026-10-06 10:00:00']))->assertCreated();

        // Another table at the same time is fine.
        $this->api('POST', $this->url(), $token, $this->booking($this->table('T2')))->assertCreated();
    }

    public function test_cancelled_and_completed_reservations_do_not_block_the_table(): void
    {
        $table = $this->table();
        $this->makeReservation($table, '2026-10-06 10:00:00', '2026-10-06 11:30:00', 'cancelled');
        $this->makeReservation($table, '2026-10-06 12:00:00', '2026-10-06 13:00:00', 'no_show');

        $token = $this->tokenFor($this->manager);
        $this->api('POST', $this->url(), $token, $this->booking($table))->assertCreated();
        $this->api('POST', $this->url(), $token, $this->booking($table, ['reservation_date' => '2026-10-06 12:00:00', 'reservation_end' => '2026-10-06 13:00:00']))->assertCreated();
    }

    // ── Branch isolation ─────────────────────────────────────────────────

    public function test_cannot_book_a_table_of_another_branch(): void
    {
        $foreign = $this->makeTable($this->makePlan($this->otherBranch), 'X1');
        $token   = $this->tokenFor($this->manager);

        $this->api('POST', $this->url(), $token, $this->booking($foreign))->assertNotFound();
        $this->api('GET', $this->base('manager', $this->otherBranch) . '/reservations', $token)->assertForbidden();
    }

    public function test_reservations_of_another_branch_are_not_reachable(): void
    {
        $foreign = $this->makeReservation($this->makeTable($this->makePlan($this->otherBranch), 'X1'), '2026-10-06 10:00:00', '2026-10-06 11:00:00');
        $token   = $this->tokenFor($this->manager);

        $this->api('GET', $this->url("/{$foreign->uuid}"), $token)->assertNotFound();
        $this->api('PATCH', $this->url("/{$foreign->uuid}"), $token, ['customer_name' => 'Hacked'])->assertNotFound();
        $this->api('POST', $this->url("/{$foreign->uuid}/status"), $token, ['status' => 'confirmed'])->assertNotFound();

        $this->api('GET', $this->url(), $token)->assertOk()->assertJsonCount(0, 'reservations.data');
    }

    public function test_reservations_need_the_reservations_feature(): void
    {
        $this->subscriptionPlan->features()->detach(Feature::where('key', 'reservations')->value('feature_id'));

        $this->api('GET', $this->url(), $this->tokenFor($this->manager))->assertForbidden()->assertJsonPath('required_feature', 'reservations');
    }

    // ── Update ───────────────────────────────────────────────────────────

    public function test_pending_reservation_can_be_edited(): void
    {
        $table = $this->table();
        $res   = $this->makeReservation($table, '2026-10-06 10:00:00', '2026-10-06 11:00:00', 'pending');
        $token = $this->tokenFor($this->manager);

        // Extending the end time over its own slot must not clash with itself.
        $this->api('PATCH', $this->url("/{$res->uuid}"), $token, ['reservation_end' => '2026-10-06 12:30:00', 'customer_name' => 'New Name', 'party_size' => 4])
            ->assertOk()->assertJsonPath('reservation.customer_name', 'New Name');

        $this->assertSame('2026-10-06 12:30:00', $res->fresh()->reservation_end->format('Y-m-d H:i:s'));
    }

    public function test_edit_is_checked_against_other_bookings_and_capacity(): void
    {
        $table = $this->table('T1', 4);
        $this->makeReservation($table, '2026-10-06 13:00:00', '2026-10-06 14:00:00');
        $res   = $this->makeReservation($table, '2026-10-06 10:00:00', '2026-10-06 11:00:00', 'pending');
        $token = $this->tokenFor($this->manager);

        $this->api('PATCH', $this->url("/{$res->uuid}"), $token, ['reservation_end' => '2026-10-06 13:30:00'])->assertStatus(409);
        $this->api('PATCH', $this->url("/{$res->uuid}"), $token, ['party_size' => 9])->assertStatus(422)->assertJsonValidationErrors('party_size');
    }

    public function test_reservation_can_be_moved_to_another_table(): void
    {
        $plan  = $this->makePlan($this->mainBranch);
        $small = $this->makeTable($plan, 'T1', 2);
        $big   = $this->makeTable($plan, 'T2', 8);
        $res   = $this->makeReservation($small, '2026-10-06 10:00:00', '2026-10-06 11:00:00', 'pending');
        $res->update(['party_size' => 6]);

        $this->api('PATCH', $this->url("/{$res->uuid}"), $this->tokenFor($this->manager), ['table_uuid' => $big->uuid])
            ->assertOk()->assertJsonPath('reservation.table.table_name', 'T2');
    }

    public function test_seated_or_finished_reservations_cannot_be_edited(): void
    {
        $table = $this->table();
        $token = $this->tokenFor($this->manager);

        foreach (['seated', 'completed', 'cancelled'] as $status) {
            $res = $this->makeReservation($table, '2026-10-06 10:00:00', '2026-10-06 11:00:00', $status);
            $this->api('PATCH', $this->url("/{$res->uuid}"), $token, ['customer_name' => 'X'])->assertStatus(409);
            $res->delete();
        }
    }

    // ── Status flow ──────────────────────────────────────────────────────

    public function test_status_flow_updates_the_table_only_when_seating_and_completing(): void
    {
        $table = $this->table();
        $res   = $this->makeReservation($table, '2026-10-06 10:00:00', '2026-10-06 11:00:00', 'pending');
        $token = $this->tokenFor($this->manager);
        $url   = $this->url("/{$res->uuid}/status");

        $this->api('POST', $url, $token, ['status' => 'confirmed'])->assertOk()->assertJsonPath('reservation.status', 'confirmed');
        $this->assertSame('available', $table->fresh()->status); // reserved stays manual

        $this->api('POST', $url, $token, ['status' => 'seated'])->assertOk();
        $this->assertSame('occupied', $table->fresh()->status);

        $this->api('POST', $url, $token, ['status' => 'completed'])->assertOk();
        $this->assertSame('cleaning', $table->fresh()->status);
    }

    public function test_invalid_status_jumps_are_refused(): void
    {
        $table = $this->table();
        $res   = $this->makeReservation($table, '2026-10-06 10:00:00', '2026-10-06 11:00:00', 'pending');
        $token = $this->tokenFor($this->manager);
        $url   = $this->url("/{$res->uuid}/status");

        $this->api('POST', $url, $token, ['status' => 'seated'])->assertStatus(422);     // must be confirmed first
        $this->api('POST', $url, $token, ['status' => 'completed'])->assertStatus(422);
        $this->api('POST', $url, $token, ['status' => 'bogus'])->assertStatus(422)->assertJsonValidationErrors('status');

        $this->api('POST', $url, $token, ['status' => 'cancelled'])->assertOk();
        $this->api('POST', $url, $token, ['status' => 'confirmed'])->assertStatus(422);  // cancelled is final
    }

    public function test_cancelling_or_no_show_leaves_the_table_status_alone(): void
    {
        $table = $this->table();
        $table->update(['status' => 'reserved']); // set manually by staff
        $res   = $this->makeReservation($table, '2026-10-06 10:00:00', '2026-10-06 11:00:00', 'confirmed');

        $this->api('POST', $this->url("/{$res->uuid}/status"), $this->tokenFor($this->manager), ['status' => 'no_show'])->assertOk();
        $this->assertSame('reserved', $table->fresh()->status);
    }

    public function test_cannot_seat_onto_an_occupied_table(): void
    {
        $table = $this->table();
        $table->update(['status' => 'occupied']);
        $res = $this->makeReservation($table, '2026-10-06 10:00:00', '2026-10-06 11:00:00', 'confirmed');

        $this->api('POST', $this->url("/{$res->uuid}/status"), $this->tokenFor($this->manager), ['status' => 'seated'])->assertStatus(409);
        $this->assertSame('confirmed', $res->fresh()->status);
    }

    public function test_reservation_on_a_removed_table_cannot_be_seated_or_edited(): void
    {
        $table = $this->table();
        $res   = $this->makeReservation($table, '2026-10-06 10:00:00', '2026-10-06 11:00:00', 'confirmed');
        $table->delete();
        $token = $this->tokenFor($this->manager);

        $this->api('POST', $this->url("/{$res->uuid}/status"), $token, ['status' => 'seated'])->assertStatus(409);
        $this->api('PATCH', $this->url("/{$res->uuid}"), $token, ['customer_name' => 'X'])->assertStatus(409);
        $this->api('POST', $this->url("/{$res->uuid}/status"), $token, ['status' => 'cancelled'])->assertOk();
    }

    // ── List ─────────────────────────────────────────────────────────────

    public function test_list_filters_and_pagination(): void
    {
        $plan  = $this->makePlan($this->mainBranch, 'Indoor');
        $patio = $this->makePlan($this->mainBranch, 'Patio', false);
        $t1    = $this->makeTable($plan, 'T1');
        $p1    = $this->makeTable($patio, 'P1');

        $a = $this->makeReservation($t1, '2026-10-06 10:00:00', '2026-10-06 11:00:00', 'confirmed');
        $a->update(['customer_name' => 'Maria Santos', 'customer_phone' => '09998887777']);
        $this->makeReservation($t1, '2026-10-07 10:00:00', '2026-10-07 11:00:00', 'pending');
        $this->makeReservation($p1, '2026-10-06 12:00:00', '2026-10-06 13:00:00', 'cancelled');

        $token = $this->tokenFor($this->manager);

        $this->api('GET', $this->url(), $token)->assertOk()->assertJsonPath('reservations.total', 3)
            ->assertJsonPath('reservations.data.0.uuid', $a->uuid); // ordered by start time

        $this->api('GET', $this->url('?date=2026-10-06'), $token)->assertJsonCount(2, 'reservations.data');
        $this->api('GET', $this->url('?from=2026-10-07&to=2026-10-08'), $token)->assertJsonCount(1, 'reservations.data');
        $this->api('GET', $this->url('?status=cancelled'), $token)->assertJsonCount(1, 'reservations.data');
        $this->api('GET', $this->url("?table_uuid={$p1->uuid}"), $token)->assertJsonCount(1, 'reservations.data');
        $this->api('GET', $this->url("?floor_plan_uuid={$plan->uuid}"), $token)->assertJsonCount(2, 'reservations.data');
        $this->api('GET', $this->url('?search=maria'), $token)->assertJsonCount(1, 'reservations.data');
        $this->api('GET', $this->url('?search=8887777'), $token)->assertJsonCount(1, 'reservations.data');
        $this->api('GET', $this->url('?per_page=2'), $token)->assertJsonCount(2, 'reservations.data')->assertJsonPath('reservations.last_page', 2);

        $this->api('GET', $this->url('?status=nope'), $token)->assertStatus(422);
        $this->api('GET', $this->url('?date=06-10-2026'), $token)->assertStatus(422);
    }

    // ── Register (POS device) ────────────────────────────────────────────

    public function test_register_lists_todays_reservations_read_only(): void
    {
        $table = $this->table();
        $this->makeReservation($table, '2026-10-05 12:00:00', '2026-10-05 13:00:00'); // today (Monday)
        $this->makeReservation($table, '2026-10-06 12:00:00', '2026-10-06 13:00:00'); // tomorrow

        $device = $this->device();

        $this->api('GET', '/api/pos/device/reservations', $device)->assertOk()->assertJsonCount(1, 'reservations.data');
        $this->api('GET', '/api/pos/device/reservations?date=2026-10-06', $device)->assertOk()->assertJsonCount(1, 'reservations.data');

        // No write routes on the register.
        $this->api('POST', '/api/pos/device/reservations', $device, $this->booking($table))->assertStatus(405);
    }

    public function test_register_only_sees_its_own_branchs_reservations(): void
    {
        $this->makeReservation($this->makeTable($this->makePlan($this->otherBranch), 'X1'), '2026-10-05 12:00:00', '2026-10-05 13:00:00');

        $this->api('GET', '/api/pos/device/reservations', $this->device())->assertOk()->assertJsonCount(0, 'reservations.data');
    }
}
