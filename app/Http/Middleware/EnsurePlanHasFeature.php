<?php

namespace App\Http\Middleware;

use App\Models\PosDevice;
use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsurePlanHasFeature
{
    public function handle(Request $request, Closure $next, string $feature): Response
    {
        $user = $request->user();

        // A POS device has no plan of its own: it uses its cafe owner's.
        if ($user instanceof PosDevice) {
            $user = $user->loadMissing('branch.cafe.owner')->branch?->cafe?->owner;
        }

        if (! $user instanceof User || ! $user->canAccessFeature($feature)) {
            return response()->json([
                'success'          => false,
                'message'          => 'Your current subscription plan does not include access to this feature. Please upgrade your plan.',
                'required_feature' => $feature,
            ], 403);
        }

        return $next($request);
    }
}
