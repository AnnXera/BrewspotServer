<?php

namespace App\Http\Controllers;

use App\Http\Requests\UpdateCafeOpeningHoursRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CafeOpeningHourController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $cafe = $request->user()->cafes()->first();

        if (!$cafe) {
            return response()->json([
                'success' => false,
                'message' => 'Cafe not found.',
            ], 404);
        }

        $openingHours = $cafe->openingHours()->orderByRaw("FIELD(day_of_week, 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday')")->get();

        return response()->json([
            'success' => true,
            'data'    => $openingHours,
        ]);
    }

    public function update(UpdateCafeOpeningHoursRequest $request): JsonResponse
    {
        $cafe = $request->user()->cafes()->first();

        if (!$cafe) {
            return response()->json([
                'success' => false,
                'message' => 'Cafe not found.',
            ], 404);
        }

        $validated = $request->validated();

        foreach ($validated['hours'] as $hourData) {
            $cafe->openingHours()->updateOrCreate(
                ['day_of_week' => $hourData['day_of_week']],
                [
                    'is_closed'  => $hourData['is_closed'],
                    'open_time'  => $hourData['open_time'],
                    'close_time' => $hourData['close_time'],
                ]
            );
        }

        return response()->json([
            'success' => true,
            'message' => 'Opening hours updated successfully.',
            'data'    => $cafe->openingHours()->orderByRaw("FIELD(day_of_week, 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday')")->get(),
        ]);
    }
}
