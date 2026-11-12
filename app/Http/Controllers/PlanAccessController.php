<?php

namespace App\Http\Controllers;

use App\Models\Feature;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PlanAccessController extends Controller
{
    /**
     * GET /api/plan-access
     *
     * Which plan features the signed-in user's cafe can use, so pages (such as the dashboards)
     * can show a locked card up front instead of waiting for a 403. Each feature maps to null
     * when usable, or to the same reason the plan gate returns:
     * 'feature_not_in_plan', 'subscription_expired' or 'no_subscription'.
     */
    public function show(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $features = Feature::query()->get(['key', 'name']);

        $isAdmin = $user->role && strtolower($user->role->role_name) === 'admin';
        $owner = $isAdmin ? null : $user->featureOwner();
        $running = $owner?->runningSubscription();
        $planKeys = $running?->plan?->features?->pluck('key')->all() ?? [];

        $lapsedReason = null;
        if (! $isAdmin && ! $running) {
            $lapsedReason = $owner
                && $owner->subscriptions()->whereIn('status', ['active', 'expired', 'cancelled', 'past_due'])->exists()
                ? 'subscription_expired'
                : 'no_subscription';
        }

        $access = [];
        foreach ($features as $feature) {
            $access[$feature->key] = [
                'name'   => $feature->name,
                'reason' => match (true) {
                    $isAdmin                               => null,
                    $lapsedReason !== null                 => $lapsedReason,
                    in_array($feature->key, $planKeys, true) => null,
                    default                                => 'feature_not_in_plan',
                },
            ];
        }

        return response()->json([
            'success'  => true,
            'features' => $access,
        ]);
    }
}
