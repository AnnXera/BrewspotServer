<?php

namespace App\Http\Middleware;

use App\Models\PosDevice;
use App\Services\PosDeviceService;
use App\Support\OpeningWindows;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Symfony\Component\HttpFoundation\Response;

/**
 * The register only works during the cafe's opening hours. Runs after `pos.device`.
 * Outside hours every register action is refused and a signed-in cashier is locked out;
 * reading the device and locking it still work so the register can show a closed screen.
 */
class EnsurePosOpen
{
    public function __construct(private readonly PosDeviceService $devices)
    {
    }

    public function handle(Request $request, Closure $next): Response
    {
        if (! config('pos.enforce_opening_hours')) {
            return $next($request);
        }

        /** @var PosDevice $device */
        $device = $request->user();
        $cafeId = $device->branch?->cafe_id;

        if (! $cafeId) {
            return $next($request);
        }

        $status = OpeningWindows::forCafe($cafeId)->status(Carbon::now());

        if ($status['is_open']) {
            return $next($request);
        }

        if ($device->active_staff_id) {
            $this->devices->lock($device);
        }

        return response()->json([
            'success'  => false,
            'closed'   => true,
            'opens_at' => $status['opens_at'],
            'message'  => 'The cafe is closed. The register opens again during operating hours.',
        ], 423);
    }
}
