<?php

namespace App\Repository;

use App\Models\Cafe;
use App\Models\CafeOpeningHour;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class CafeOpeningHourRepository
{
    /** The cafe's hours ordered Monday-first, following CafeOpeningHour::DAYS. */
    public function weekForCafe(Cafe $cafe): Collection
    {
        return $cafe->openingHours()->get()
            ->sortBy(fn ($h) => array_search($h->day_of_week, CafeOpeningHour::DAYS))
            ->values();
    }

    /**
     * @param array<int, array{day_of_week: string, is_closed: bool, is_24_hours: bool, open_time: ?string, close_time: ?string}> $rows
     */
    public function upsertDays(Cafe $cafe, array $rows): void
    {
        DB::transaction(function () use ($cafe, $rows) {
            foreach ($rows as $row) {
                $cafe->openingHours()->updateOrCreate(
                    ['day_of_week' => $row['day_of_week']],
                    $row
                );
            }
        });
    }
}
