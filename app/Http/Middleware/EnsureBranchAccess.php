<?php

namespace App\Http\Middleware;

use App\Models\CafeBranch;
use App\Models\CafeStaff;
use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Guards every /{owner|manager}/branches/{branchUuid}/... route.
 *
 * - Owner:   the branch must belong to one of their cafes.
 * - Manager: they must have an active cafe_staff assignment at the branch.
 * - Anyone else is rejected.
 *
 * A missing branch returns the same 403 as a forbidden one so branch UUIDs
 * can't be probed. The resolved branch is stored on the request as 'branch'.
 */
class EnsureBranchAccess
{
    public function handle(Request $request, Closure $next): Response
    {
        $user   = $request->user();
        $branch = CafeBranch::with('cafe')->where('uuid', $request->route('branchUuid'))->first();

        if (! $user instanceof User || ! $branch || ! $this->canAccess($user, $branch)) {
            return response()->json([
                'success' => false,
                'message' => 'You do not have access to this branch.',
            ], 403);
        }

        $request->attributes->set('branch', $branch);

        return $next($request);
    }

    private function canAccess(User $user, CafeBranch $branch): bool
    {
        if ($user->isOwner()) {
            return $branch->cafe && $branch->cafe->user_id === $user->user_id;
        }

        if ($user->isManager()) {
            return CafeStaff::where('user_id', $user->user_id)
                ->where('branch_id', $branch->branch_id)
                ->active()
                ->exists();
        }

        return false;
    }
}
