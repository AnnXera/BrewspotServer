<?php

namespace App\Http\Controllers;

use App\Http\Requests\UpdateCafeOpeningHoursRequest;
use App\Http\Resources\CafeOpeningHourResource;
use App\Models\Cafe;
use App\Models\CafeOpeningHour;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class CafeOpeningHourController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $cafe = $request->user()->cafes()->first();

        if (! $cafe) {
            return response()->json([
                'success' => false,
                'message' => 'Cafe not found.',
            ], 404);
        }

        $cafe->ensureOpeningHours();

        return response()->json([
            'success' => true,
            'data'    => $this->week($cafe),
        ]);
    }

    public function update(UpdateCafeOpeningHoursRequest $request): JsonResponse
    {
        $cafe = $request->user()->cafes()->first();

        if (! $cafe) {
            return response()->json([
                'success' => false,
                'message' => 'Cafe not found.',
            ], 404);
        }

        DB::transaction(function () use ($cafe, $request) {
            foreach ($request->validated('hours') as $day) {
                $is24     = ! empty($day['is_24_hours']);
                $hasTimes = ! $day['is_closed'] && ! $is24;

                $cafe->openingHours()->updateOrCreate(
                    ['day_of_week' => $day['day_of_week']],
                    [
                        'is_closed'   => $day['is_closed'],
                        'is_24_hours' => ! $day['is_closed'] && $is24,
                        'open_time'   => $hasTimes ? $day['open_time'] : null,
                        'close_time'  => $hasTimes ? $day['close_time'] : null,
                    ]
                );
            }
        });

        return response()->json([
            'success' => true,
            'message' => 'Opening hours updated successfully.',
            'data'    => $this->week($cafe),
        ]);
    }

    private function week(Cafe $cafe)
    {
        return CafeOpeningHourResource::collection(
            $cafe->openingHours()->get()->sortBy(fn ($h) => array_search($h->day_of_week, CafeOpeningHour::DAYS))->values()
        );
    }
}
