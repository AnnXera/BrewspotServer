<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsurePlanHasFeature
{
    public function handle(Request $request, Closure $next, string $feature): Response
    {
        $user = $request->user();

        if (! $user || ! $user->canAccessFeature($feature)) {
            return response()->json([
                'success'          => false,
                'message'          => 'Your current subscription plan does not include access to this feature. Please upgrade your plan.',
                'required_feature' => $feature,
            ], 403);
        }

        return $next($request);
    }
}
