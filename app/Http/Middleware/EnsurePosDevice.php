<?php

namespace App\Http\Middleware;

use App\Models\PosDevice;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Symfony\Component\HttpFoundation\Response;

/**
 * Only a registered, non-revoked POS device token may call /pos/device/*.
 * User tokens (owner/manager dashboard logins) are rejected here, and the
 * branch always comes from the device — never from the request body.
 */
class EnsurePosDevice
{
    public function handle(Request $request, Closure $next): Response
    {
        $device = $request->user();

        if (! $device instanceof PosDevice || $device->isRevoked()) {
            return response()->json([
                'success'        => false,
                'message'        => 'This device is not registered as a register. Please set it up again.',
                'requires_setup' => true,
            ], 401);
        }

        $device->loadMissing('branch.cafe.owner');
        $branch = $device->branch;

        if (! $branch || $branch->status !== 'active') {
            return response()->json([
                'success' => false,
                'message' => 'This branch is not active. The register is unavailable.',
            ], 403);
        }

        if (! $branch->cafe?->owner?->canAccessFeature('pos_system')) {
            return response()->json([
                'success'          => false,
                'message'          => 'Your cafe\'s subscription plan does not include the POS system.',
                'required_feature' => 'pos_system',
            ], 403);
        }

        $device->forceFill(['last_used_at' => Carbon::now()])->saveQuietly();

        return $next($request);
    }
}
