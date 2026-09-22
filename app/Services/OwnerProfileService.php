<?php

namespace App\Services;

use App\Http\Resources\UserResource;
use App\Http\Resources\CafeResource;
use App\Http\Resources\CafeBranchResource;
use App\Http\Resources\BranchSummaryResource;
use App\Http\Resources\SubscriptionPlanResource;
use App\Http\Resources\SubscriptionResource;
use App\Models\User;
use App\Repository\OwnerProfileRepository;
use App\Repository\SubscriptionRepository;
use Illuminate\Support\Facades\Log;

class OwnerProfileService
{
    public function __construct(
        private readonly OwnerProfileRepository $repo,
        private readonly SubscriptionRepository $subscriptionRepo,
        private readonly \App\Repository\PaymentRepository $paymentRepo
    ) {}

    public function getProfile(User $owner): array
    {
        $owner = $this->repo->findOwnerWithRole($owner->user_id);

        return [
            'success' => true,
            'user'    => new UserResource($owner),
        ];
    }

    public function getCafes(User $owner): array
    {
        $cafes = $this->repo->findCafesByOwner($owner->user_id);

        return [
            'success' => true,
            'cafes'   => CafeResource::collection($cafes),
        ];
    }

    /**
     * List view — name and picture only, for the branch card grid.
     * Paginated, 6 per page by default.
     */
    public function getBranches(User $owner, int $perPage = 6, ?string $search = null, ?string $status = null): array
    {
        $branches = $this->repo->findBranchesByOwner($owner->user_id, $perPage, $search, $status);

        return [
            'success'  => true,
            'branches' => $branches->through(fn ($branch) => new BranchSummaryResource($branch)),
        ];
    }

    /**
     * Detail view — full branch info, documents, status, etc.
     */
    public function getBranch(User $owner, string $branchUuid): array
    {
        $branch = $this->repo->findBranchByUuid($owner->user_id, $branchUuid);

        if (! $branch) {
            Log::channel('owner')->warning('Branch not found or not owned by this user.', [
                'owner_uuid'  => $owner->uuid,
                'branch_uuid' => $branchUuid,
            ]);

            return [
                'success' => false,
                'message' => 'Branch not found.',
            ];
        }

        return [
            'success' => true,
            'branch'  => new CafeBranchResource($branch),
        ];
    }

    public function getCurrentPlan(User $owner): array
    {
        $subscription = $this->subscriptionRepo->findCurrentByUserId($owner->user_id);

        if (! $subscription) {
            Log::channel('owner')->warning('Owner checked current plan — no active subscription.', [
                'owner_uuid' => $owner->uuid,
            ]);

            return [
                'success'        => false,
                'message'        => 'You have no active subscription.',
                'renewal_offer'  => $this->buildRenewalOffer($owner),
            ];
        }

        Log::channel('owner')->info('Owner viewed current subscription.', [
            'owner_uuid'        => $owner->uuid,
            'subscription_uuid' => $subscription->uuid,
            'plan_name'         => $subscription->plan?->sub_name,
        ]);

        return [
            'success'      => true,
            'subscription' => new SubscriptionResource($subscription),
        ];
    }

    /**
     * What an owner with no running term should be offered when they come back.
     *
     * Payment only happens once a term runs out, so the plan the owner settled on before
     * that point has to survive the lapse — otherwise a booked upgrade quietly disappears
     * and they are dropped back onto a bare plan list with nothing to pay for.
     *
     * A booked plan change wins over the plan that just ended, since that is the plan the
     * owner chose to move to.
     */
    private function buildRenewalOffer(User $owner): ?array
    {
        $ended = $this->subscriptionRepo->findLatestEndedByUserId($owner->user_id);

        if (! $ended) {
            return null;
        }

        $plan = $ended->pendingPlan ?? $ended->plan;

        if (! $plan) {
            return null;
        }

        return [
            'plan'           => new SubscriptionPlanResource($plan),
            'billing_cycle'  => $ended->pending_billing_cycle ?? $ended->billing_cycle ?? 'monthly',
            'was_scheduled'  => $ended->pendingPlan !== null,
            'previous_plan'  => $ended->plan?->sub_name,
            'ended_on'       => $ended->end_date?->toISOString(),
        ];
    }

    public function getPlanHistory(User $owner, int $perPage = 15)
    {
        Log::channel('owner')->info('Owner viewed payment history.', [
            'owner_uuid' => $owner->uuid,
            'per_page'   => $perPage,
        ]);

        $history = $this->paymentRepo->getSubscriptionPaymentsByUserId($owner->user_id, $perPage);

        return $history->through(fn ($payment) => new \App\Http\Resources\PaymentResource($payment));
    }
}