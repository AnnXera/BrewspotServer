<?php

namespace App\Http\Middleware;

use App\Models\Feature;
use App\Models\PosDevice;
use App\Models\User;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsurePlanHasFeature
{
    /**
     * @param  string|null  $mode  'writes' gates only changes: GET/HEAD requests pass, so the
     *                             data stays viewable without the feature.
     */
    public function handle(Request $request, Closure $next, string $feature, ?string $mode = null): Response
    {
        if ($mode === 'writes' && $request->isMethodSafe()) {
            return $next($request);
        }

        $user = $request->user();
        $viaStaff = false;

        // A POS device has no plan of its own: it uses its cafe owner's.
        if ($user instanceof PosDevice) {
            $user = $user->loadMissing('branch.cafe.owner')->branch?->cafe?->owner;
            $viaStaff = true;
        } elseif ($user instanceof User && $user->isEmployee()) {
            $viaStaff = true;
        }

        if (! $user instanceof User) {
            return self::deny($feature, 'no_subscription', true);
        }

        $reason = $user->featureDenialReason($feature);

        if ($reason !== null) {
            return self::deny($feature, $reason, $viaStaff);
        }

        return $next($request);
    }

    /**
     * The 403 every plan-gated endpoint returns, so the client can show one popup:
     * "upgrade" when the plan lacks the feature, "renew" when the term has lapsed.
     */
    public static function deny(string $feature, string $reason, bool $viaStaff = false): JsonResponse
    {
        $name = Feature::where('key', $feature)->value('name') ?? str_replace('_', ' ', ucfirst($feature));

        $message = match ($reason) {
            'subscription_expired' => $viaStaff
                ? "The cafe's subscription has expired. Ask the cafe owner to renew it to use {$name}."
                : "Your subscription has expired. Renew it to use {$name} again.",
            'no_subscription' => $viaStaff
                ? "The cafe has no active subscription. Ask the cafe owner to subscribe to use {$name}."
                : "You don't have an active subscription. Choose a plan to use {$name}.",
            default => $viaStaff
                ? "The cafe's plan does not include {$name}. Ask the cafe owner to upgrade the plan."
                : "Your current plan does not include {$name}. Upgrade your plan to use it.",
        };

        return response()->json([
            'success'          => false,
            'code'             => 'PLAN_FEATURE_LOCKED',
            'reason'           => $reason,
            'message'          => $message,
            'required_feature' => $feature,
            'feature_name'     => $name,
        ], 403);
    }
}
