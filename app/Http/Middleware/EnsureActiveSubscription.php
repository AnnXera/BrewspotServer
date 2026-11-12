<?php

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Blocks a route unless the cafe has a running subscription (any plan). Used for the owner
 * and manager dashboards: without a subscription they can't be seen at all. Answers with the
 * same 403 as the plan-feature gate (required_feature = the $area name, e.g. "dashboard").
 */
class EnsureActiveSubscription
{
    public function handle(Request $request, Closure $next, string $area = 'dashboard'): Response
    {
        $user = $request->user();

        if (! $user instanceof User) {
            return EnsurePlanHasFeature::deny($area, 'no_subscription', true);
        }

        $reason = $user->subscriptionDenialReason();

        if ($reason !== null) {
            return EnsurePlanHasFeature::deny($area, $reason, $user->isEmployee());
        }

        return $next($request);
    }
}
