<?php

namespace App\Services;

use App\Http\Resources\CafeOpeningHourResource;
use App\Models\CafeBranch;
use App\Models\User;
use App\Repository\CafeOpeningHourRepository;

class CafeOpeningHourService
{
    public function __construct(
        private readonly CafeOpeningHourRepository $repo
    ) {}

    public function forOwner(User $owner): array
    {
        $cafe = $owner->cafes()->first();

        if (! $cafe) {
            return $this->cafeNotFound();
        }

        $cafe->ensureOpeningHours();

        return [
            'success' => true,
            'data'    => CafeOpeningHourResource::collection($this->repo->weekForCafe($cafe)),
        ];
    }

    /** Read-only view of a branch's cafe hours (for reservation slots). */
    public function forBranch(CafeBranch $branch): array
    {
        $cafe = $branch->cafe;

        return [
            'success' => true,
            'data'    => $cafe ? CafeOpeningHourResource::collection($this->repo->weekForCafe($cafe)) : [],
        ];
    }

    public function update(User $owner, array $hours): array
    {
        $cafe = $owner->cafes()->first();

        if (! $cafe) {
            return $this->cafeNotFound();
        }

        $this->repo->upsertDays($cafe, array_map(function (array $day) {
            $is24     = ! empty($day['is_24_hours']);
            $hasTimes = ! $day['is_closed'] && ! $is24;

            return [
                'day_of_week' => $day['day_of_week'],
                'is_closed'   => $day['is_closed'],
                'is_24_hours' => ! $day['is_closed'] && $is24,
                'open_time'   => $hasTimes ? $day['open_time'] : null,
                'close_time'  => $hasTimes ? $day['close_time'] : null,
            ];
        }, $hours));

        return [
            'success' => true,
            'message' => 'Opening hours updated successfully.',
            'data'    => CafeOpeningHourResource::collection($this->repo->weekForCafe($cafe)),
        ];
    }

    private function cafeNotFound(): array
    {
        return ['success' => false, 'http' => 404, 'message' => 'Cafe not found.'];
    }
}
