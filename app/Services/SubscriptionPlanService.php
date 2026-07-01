<?php

namespace App\Services;

use App\Http\Resources\SubscriptionPlanResource;
use App\Models\SubscriptionPlan;
use App\Models\User;
use App\Repository\SubscriptionPlanRepository;
use App\Repository\SubscriptionRepository;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class SubscriptionPlanService
{
    public function __construct(
        private readonly SubscriptionPlanRepository $repo,
        private readonly SubscriptionRepository $subscriptionRepo
    ) {}

    public function listPlans(int $perPage = 15)
    {
        Log::channel('admin')->info('Admin listed subscription plans.', [
            'per_page' => $perPage,
        ]);

        $plans = $this->repo->list($perPage);

        return $plans->through(fn ($plan) => new SubscriptionPlanResource($plan));
    }

    public function getPlan(string $uuid): array
    {
        $plan = $this->repo->findByUuid($uuid);

        if (! $plan) {
            Log::channel('admin')->warning('Admin viewed subscription plan — not found.', [
                'plan_uuid' => $uuid,
            ]);

            return ['success' => false, 'message' => 'Subscription plan not found.'];
        }

        Log::channel('admin')->info('Admin viewed subscription plan.', [
            'plan_uuid' => $plan->uuid,
        ]);

        return ['success' => true, 'plan' => new SubscriptionPlanResource($plan)];
    }

    public function createPlan(array $payload): array
    {
        $plan = $this->repo->create($payload);

        Log::channel('admin')->info('Subscription plan created.', [
            'plan_uuid' => $plan->uuid,
            'sub_name'  => $plan->sub_name,
            'price'     => $plan->price,
            'is_active' => $plan->is_active,
        ]);

        return [
            'success' => true,
            'message' => 'Subscription plan created successfully.',
            'plan'    => new SubscriptionPlanResource($plan),
        ];
    }

    public function updatePlan(string $uuid, array $payload): array
    {
        $plan = $this->repo->findByUuid($uuid);

        if (! $plan) {
            Log::channel('admin')->warning('Subscription plan update failed — not found.', [
                'plan_uuid' => $uuid,
            ]);

            return ['success' => false, 'message' => 'Subscription plan not found.'];
        }

        $before = $plan->only(['sub_name', 'price', 'max_branches', 'duration_days', 'is_active']);

        $plan = $this->repo->update($plan, $payload);

        Log::channel('admin')->info('Subscription plan updated.', [
            'plan_uuid' => $plan->uuid,
            'before'    => $before,
            'after'     => $plan->only(['sub_name', 'price', 'max_branches', 'duration_days', 'is_active']),
        ]);

        return [
            'success' => true,
            'message' => 'Subscription plan updated successfully.',
            'plan'    => new SubscriptionPlanResource($plan),
        ];
    }

    public function deletePlan(string $uuid): array
    {
        $plan = $this->repo->findByUuid($uuid);

        if (! $plan) {
            Log::channel('admin')->warning('Subscription plan delete failed — not found.', [
                'plan_uuid' => $uuid,
            ]);

            return ['success' => false, 'message' => 'Subscription plan not found.'];
        }

        $this->repo->delete($plan);

        Log::channel('admin')->info('Subscription plan soft deleted.', [
            'plan_uuid' => $plan->uuid,
            'sub_name'  => $plan->sub_name,
        ]);

        return ['success' => true, 'message' => 'Subscription plan deleted successfully.'];
    }

    public function restorePlan(string $uuid): array
    {
        $plan = $this->repo->findTrashedByUuid($uuid);

        if (! $plan) {
            Log::channel('admin')->warning('Subscription plan restore failed — not found in trash.', [
                'plan_uuid' => $uuid,
            ]);

            return ['success' => false, 'message' => 'Subscription plan not found in trash.'];
        }

        $plan = $this->repo->restore($plan);

        Log::channel('admin')->info('Subscription plan restored.', [
            'plan_uuid' => $plan->uuid,
            'sub_name'  => $plan->sub_name,
        ]);

        return [
            'success' => true,
            'message' => 'Subscription plan restored successfully.',
            'plan'    => new SubscriptionPlanResource($plan),
        ];
    }

    public function listActivePlansForOwner(User $owner, int $perPage = 15)
    {
        $hasSubscribedBefore = $this->subscriptionRepo->hasAnyByUserId($owner->user_id);

        Log::channel('owner')->info('Owner listed active subscription plans.', [
            'owner_uuid'            => $owner->uuid,
            'has_subscribed_before' => $hasSubscribedBefore,
        ]);

        $plans = $this->repo->listActive($perPage);

        return $plans->through(function (SubscriptionPlan $plan) use ($hasSubscribedBefore) {
            $data = (new SubscriptionPlanResource($plan))->resolve();
            $data['is_selectable'] = $this->isPlanSelectable($plan, $hasSubscribedBefore);

            return $data;
        });
    }

    public function getActivePlanForOwner(User $owner, string $uuid): array
    {
        $plan = $this->repo->findActiveByUuid($uuid);

        if (! $plan) {
            Log::channel('owner')->warning('Owner viewed subscription plan — not found or inactive.', [
                'owner_uuid' => $owner->uuid,
                'plan_uuid'  => $uuid,
            ]);

            return ['success' => false, 'message' => 'Subscription plan not found.'];
        }

        $hasSubscribedBefore = $this->subscriptionRepo->hasAnyByUserId($owner->user_id);
        $isSelectable = $this->isPlanSelectable($plan, $hasSubscribedBefore);

        Log::channel('owner')->info('Owner viewed subscription plan.', [
            'owner_uuid'    => $owner->uuid,
            'plan_uuid'     => $plan->uuid,
            'is_selectable' => $isSelectable,
        ]);

        $data = (new SubscriptionPlanResource($plan))->resolve();
        $data['is_selectable'] = $isSelectable;

        return ['success' => true, 'plan' => $data];
    }

    private function isPlanSelectable(SubscriptionPlan $plan, bool $hasSubscribedBefore): bool
    {
        $isTrial = Str::contains(strtolower($plan->sub_name), 'trial');

        return ! $isTrial || ! $hasSubscribedBefore;
    }
}