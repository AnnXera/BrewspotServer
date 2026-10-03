<?php

namespace App\Services;

use App\Http\Resources\ReservationResource;
use App\Models\BranchTable;
use App\Models\CafeBranch;
use App\Models\Reservation;
use App\Models\User;
use App\Repository\FloorPlanRepository;
use App\Repository\ReservationRepository;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Staff-entered table reservations for a branch.
 *
 * - The end time is chosen per booking.
 * - A booking holds its table for [start, end); back-to-back bookings are fine.
 * - Table status stays manual, except: seating a reservation marks the table
 *   occupied, and completing it marks the table cleaning.
 *
 * Results carry an `http` key that the controller turns into the status code.
 */
class ReservationService
{
    /** Statuses that can still be edited. */
    private const EDITABLE = ['pending', 'confirmed'];

    public function __construct(
        private readonly ReservationRepository $repo,
        private readonly FloorPlanRepository $plans
    ) {}

    // ── Read ─────────────────────────────────────────────────────────────

    public function list(CafeBranch $branch, array $filters): array
    {
        $perPage = (int) ($filters['per_page'] ?? 25);

        $page = $this->repo->paginateForBranch($branch->branch_id, $filters, $perPage);

        // Same paginated shape as the other lists: { data, current_page, last_page, total, ... }.
        return [
            'success'      => true,
            'reservations' => $page->through(fn (Reservation $r) => (new ReservationResource($r))->resolve())->toArray(),
        ];
    }

    public function show(CafeBranch $branch, string $reservationUuid): array
    {
        $reservation = $this->repo->findForBranch($reservationUuid, $branch->branch_id);

        return $reservation
            ? ['success' => true, 'reservation' => new ReservationResource($reservation)]
            : $this->notFound();
    }

    // ── Create / update ──────────────────────────────────────────────────

    public function create(User $actor, CafeBranch $branch, array $data): array
    {
        $table = $this->plans->findTableForBranch($data['table_uuid'], $branch->branch_id);

        if (! $table) {
            return ['success' => false, 'http' => 404, 'message' => 'Table not found at this branch.'];
        }

        return DB::transaction(function () use ($actor, $branch, $table, $data) {
            $this->repo->lockTable($table);

            $start = $this->parse($data['reservation_date']);
            $end   = $this->parse($data['reservation_end']);

            if ($failure = $this->checkBooking($branch, $table, (int) $data['party_size'], $start, $end, null, true)) {
                return $failure;
            }

            $reservation = $this->repo->create([
                'table_id'         => $table->table_id,
                'created_by'       => $actor->user_id,
                'customer_name'    => trim($data['customer_name']),
                'customer_phone'   => trim($data['customer_phone']),
                'customer_email'   => $data['customer_email'] ?? null,
                'party_size'       => (int) $data['party_size'],
                'reservation_date' => $start,
                'reservation_end'  => $end,
                'status'           => $data['status'] ?? 'pending',
                'notes'            => $data['notes'] ?? null,
            ]);

            return [
                'success'     => true,
                'http'        => 201,
                'message'     => "Reservation for {$reservation->customer_name} created.",
                'reservation' => new ReservationResource($this->fresh($reservation)),
            ];
        });
    }

    public function update(CafeBranch $branch, string $reservationUuid, array $data): array
    {
        $reservation = $this->repo->findForBranch($reservationUuid, $branch->branch_id);

        if (! $reservation) {
            return $this->notFound();
        }

        if (! in_array($reservation->status, self::EDITABLE, true)) {
            return ['success' => false, 'http' => 409, 'message' => "A {$reservation->status} reservation can't be edited."];
        }

        $table = $reservation->table;

        if (isset($data['table_uuid']) && $data['table_uuid'] !== $table->uuid) {
            $table = $this->plans->findTableForBranch($data['table_uuid'], $branch->branch_id);

            if (! $table) {
                return ['success' => false, 'http' => 404, 'message' => 'Table not found at this branch.'];
            }
        }

        if ($table->trashed()) {
            return ['success' => false, 'http' => 409, 'message' => 'This table was removed. Move the reservation to another table.'];
        }

        return DB::transaction(function () use ($branch, $reservation, $table, $data) {
            $this->repo->lockTable($table);

            $start     = isset($data['reservation_date']) ? $this->parse($data['reservation_date']) : $reservation->reservation_date;
            $end       = isset($data['reservation_end']) ? $this->parse($data['reservation_end']) : $reservation->reservation_end;
            $partySize = (int) ($data['party_size'] ?? $reservation->party_size);

            // Only re-check "not in the past" when the start time is being changed.
            $timeChanged = isset($data['reservation_date']);

            if ($failure = $this->checkBooking($branch, $table, $partySize, $start, $end, $reservation->reservation_id, $timeChanged)) {
                return $failure;
            }

            $reservation->update([
                'table_id'         => $table->table_id,
                'customer_name'    => isset($data['customer_name']) ? trim($data['customer_name']) : $reservation->customer_name,
                'customer_phone'   => isset($data['customer_phone']) ? trim($data['customer_phone']) : $reservation->customer_phone,
                'customer_email'   => array_key_exists('customer_email', $data) ? $data['customer_email'] : $reservation->customer_email,
                'party_size'       => $partySize,
                'reservation_date' => $start,
                'reservation_end'  => $end,
                'notes'            => array_key_exists('notes', $data) ? $data['notes'] : $reservation->notes,
            ]);

            return [
                'success'     => true,
                'message'     => 'Reservation updated.',
                'reservation' => new ReservationResource($this->fresh($reservation)),
            ];
        });
    }

    // ── Status ───────────────────────────────────────────────────────────

    public function updateStatus(CafeBranch $branch, string $reservationUuid, string $status): array
    {
        $reservation = $this->repo->findForBranch($reservationUuid, $branch->branch_id);

        if (! $reservation) {
            return $this->notFound();
        }

        $allowed = config('floor_plan.reservation_transitions')[$reservation->status] ?? [];

        if (! in_array($status, $allowed, true)) {
            return ['success' => false, 'http' => 422, 'message' => "A {$reservation->status} reservation can't be changed to {$status}."];
        }

        $table = $reservation->table;

        if ($status === 'seated') {
            if ($table->trashed()) {
                return ['success' => false, 'http' => 409, 'message' => 'This table was removed. Move the reservation to another table first.'];
            }

            if ($table->status === 'occupied') {
                return ['success' => false, 'http' => 409, 'message' => "{$table->table_name} is still occupied."];
            }
        }

        DB::transaction(function () use ($reservation, $table, $status) {
            $reservation->update(['status' => $status]);

            if ($status === 'seated') {
                $table->update(['status' => 'occupied']);
            } elseif ($status === 'completed' && ! $table->trashed()) {
                $table->update(['status' => 'cleaning']);
            }
        });

        return [
            'success'     => true,
            'message'     => "Reservation marked {$status}.",
            'reservation' => new ReservationResource($this->fresh($reservation)),
        ];
    }

    // ── Rules ────────────────────────────────────────────────────────────

    /**
     * Returns a failure result, or null when the booking is allowed.
     * $ignoreId is the reservation being edited (excluded from the overlap check).
     */
    private function checkBooking(
        CafeBranch $branch,
        BranchTable $table,
        int $partySize,
        Carbon $start,
        Carbon $end,
        ?int $ignoreId,
        bool $checkPast
    ): ?array {
        $rules = config('floor_plan.reservations');

        if ($end <= $start) {
            return $this->invalid('reservation_end', 'The end time must be after the start time.');
        }

        $minutes = $start->diffInMinutes($end);

        if ($minutes < $rules['min_duration_minutes']) {
            return $this->invalid('reservation_end', "A reservation must last at least {$rules['min_duration_minutes']} minutes.");
        }

        if ($minutes > $rules['max_duration_minutes']) {
            return $this->invalid('reservation_end', 'A reservation can last at most ' . ($rules['max_duration_minutes'] / 60) . ' hours.');
        }

        if ($checkPast && $start < Carbon::now()->subMinutes($rules['past_grace_minutes'])) {
            return $this->invalid('reservation_date', 'The reservation start time is in the past.');
        }

        if ($start > Carbon::now()->addDays($rules['max_days_ahead'])) {
            return $this->invalid('reservation_date', "Reservations can only be made up to {$rules['max_days_ahead']} days ahead.");
        }

        if ($partySize > $table->capacity) {
            return $this->invalid('party_size', "{$table->table_name} seats {$table->capacity}, but the party is {$partySize}.");
        }

        if (! $this->withinOpeningHours($this->repo->openingHours($branch->cafe_id), $start, $end)) {
            return $this->invalid('reservation_date', 'The reservation must be within the cafe\'s opening hours.');
        }

        $clash = $this->repo->findOverlap($table->table_id, $start, $end, $ignoreId);

        if ($clash) {
            return [
                'success' => false,
                'http'    => 409,
                'message' => "{$table->table_name} is already reserved from {$clash->reservation_date->format('g:i A')} to {$clash->reservation_end->format('g:i A')}.",
            ];
        }

        return null;
    }

    /**
     * True when [start, end] sits inside one continuous opening window.
     * Handles cafes that close after midnight and 24-hour days (adjacent
     * windows are merged), and skips the check when the cafe has no hours set.
     *
     * @param Collection<string, \App\Models\CafeOpeningHour> $hours keyed by day name
     */
    private function withinOpeningHours(Collection $hours, Carbon $start, Carbon $end): bool
    {
        if ($hours->isEmpty()) {
            return true;
        }

        $windows = [];

        foreach ([-1, 0, 1] as $offset) {
            $day = $start->copy()->startOfDay()->addDays($offset);
            $row = $hours->get($day->englishDayOfWeek);

            if (! $row || $row->is_closed) {
                continue;
            }

            if ($row->is_24_hours) {
                $windows[] = [$day->copy(), $day->copy()->addDay()];
                continue;
            }

            if (! $row->open_time || ! $row->close_time) {
                continue;
            }

            $open  = $day->copy()->setTimeFromTimeString($row->open_time);
            $close = $day->copy()->setTimeFromTimeString($row->close_time);

            if ($row->closesAfterMidnight()) {
                $close->addDay();
            }

            $windows[] = [$open, $close];
        }

        usort($windows, fn ($a, $b) => $a[0] <=> $b[0]);

        $merged = [];

        foreach ($windows as [$open, $close]) {
            $last = count($merged) - 1;

            if ($last >= 0 && $open <= $merged[$last][1]) {
                if ($close > $merged[$last][1]) {
                    $merged[$last][1] = $close;
                }
            } else {
                $merged[] = [$open, $close];
            }
        }

        foreach ($merged as [$open, $close]) {
            if ($start >= $open && $end <= $close) {
                return true;
            }
        }

        return false;
    }

    // ── Helpers ──────────────────────────────────────────────────────────

    /** Parsed into the app timezone (the times are stored and compared there). */
    private function parse(string $value): Carbon
    {
        return Carbon::parse($value)->setTimezone(config('app.timezone'));
    }

    private function fresh(Reservation $reservation): Reservation
    {
        return $reservation->fresh(['table.floorPlan', 'creator']);
    }

    private function notFound(): array
    {
        return ['success' => false, 'http' => 404, 'message' => 'Reservation not found.'];
    }

    private function invalid(string $field, string $message): array
    {
        return ['success' => false, 'http' => 422, 'message' => $message, 'errors' => [$field => [$message]]];
    }
}
