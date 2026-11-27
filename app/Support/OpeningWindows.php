<?php

namespace App\Support;

use App\Models\CafeOpeningHour;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Turns a cafe's weekly opening hours into concrete [open, close) windows in the app
 * timezone. Handles days that close after midnight and 24-hour days (back-to-back
 * windows are merged into one). A cafe with no hours set counts as always open.
 */
class OpeningWindows
{
    /** @param Collection<string, CafeOpeningHour> $hours keyed by day name */
    public function __construct(private readonly Collection $hours)
    {
    }

    public static function forCafe(int $cafeId): self
    {
        return new self(CafeOpeningHour::where('cafe_id', $cafeId)->get()->keyBy('day_of_week'));
    }

    public function hasHours(): bool
    {
        return $this->hours->isNotEmpty();
    }

    /**
     * Merged windows for the days from $fromOffset to $toOffset around $at.
     *
     * @return array<int, array{0: Carbon, 1: Carbon}>
     */
    public function around(Carbon $at, int $fromOffset = -1, int $toOffset = 1): array
    {
        $windows = [];

        for ($offset = $fromOffset; $offset <= $toOffset; $offset++) {
            $day = $at->copy()->startOfDay()->addDays($offset);
            $row = $this->hours->get($day->englishDayOfWeek);

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

        return $merged;
    }

    /** True when [start, end] sits inside one continuous opening window. */
    public function covers(Carbon $start, Carbon $end): bool
    {
        if (! $this->hasHours()) {
            return true;
        }

        foreach ($this->around($start) as [$open, $close]) {
            if ($start >= $open && $end <= $close) {
                return true;
            }
        }

        return false;
    }

    /** The window that $at falls in (open <= $at < close), or null when closed. */
    public function current(Carbon $at): ?array
    {
        foreach ($this->around($at) as $window) {
            if ($at >= $window[0] && $at < $window[1]) {
                return $window;
            }
        }

        return null;
    }

    public function isOpenAt(Carbon $at): bool
    {
        return ! $this->hasHours() || $this->current($at) !== null;
    }

    /** Next time the cafe opens after $at, within a week; null when closed all week. */
    public function nextOpening(Carbon $at): ?Carbon
    {
        foreach ($this->around($at, 0, 7) as [$open]) {
            if ($open > $at) {
                return $open;
            }
        }

        return null;
    }

    /**
     * Status for the register: is_open, closes_at (when open), opens_at (when closed).
     * A window that never ends inside the lookahead (open 24/7) has no closes_at.
     */
    public function status(Carbon $at): array
    {
        if (! $this->hasHours()) {
            return ['is_open' => true, 'closes_at' => null, 'opens_at' => null];
        }

        // A week ahead, so back-to-back 24-hour days merge into one window with the real closing time.
        foreach ($this->around($at, -1, 7) as [$open, $close]) {
            if ($at >= $open && $at < $close) {
                $allWeek = $at->diffInDays($close, true) >= 7;

                return [
                    'is_open'   => true,
                    'closes_at' => $allWeek ? null : $close->toIso8601String(),
                    'opens_at'  => null,
                ];
            }
        }

        return [
            'is_open'   => false,
            'closes_at' => null,
            'opens_at'  => $this->nextOpening($at)?->toIso8601String(),
        ];
    }
}
