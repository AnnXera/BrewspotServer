<?php

namespace App\Services;

use App\Http\Resources\SubscriptionPlanResource;
use App\Repository\SubscriptionPlanRepository;
use Illuminate\Support\Facades\Log;

class SubscriptionPlanService
{
    public function __construct(
        private readonly SubscriptionPlanRepository $repo
    ) {}

    public function listPlans(int $perPage = 15)
    {
        $plans = $this->repo->list($perPage);

        return $plans->through(fn ($plan) => new SubscriptionPlanResource($plan));
    }

    public function getPlan(string $uuid): array
    {
        $plan = $this->repo->findByUuid($uuid);

        if (! $plan) {
            return ['success' => false, 'message' => 'Subscription plan not found.'];
        }

        return ['success' => true, 'plan' => new SubscriptionPlanResource($plan)];
    }

    public function createPlan(array $payload): array
    {
        $plan = $this->repo->create($payload);

        Log::channel('admin')->info('Subscription plan created.', [
            'plan_uuid' => $plan->uuid,
            'sub_name'  => $plan->sub_name,
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
            return ['success' => false, 'message' => 'Subscription plan not found.'];
        }

        $plan = $this->repo->update($plan, $payload);

        Log::channel('admin')->info('Subscription plan updated.', ['plan_uuid' => $plan->uuid]);

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
            return ['success' => false, 'message' => 'Subscription plan not found.'];
        }

        $this->repo->delete($plan);

        Log::channel('admin')->info('Subscription plan soft deleted.', ['plan_uuid' => $plan->uuid]);

        return ['success' => true, 'message' => 'Subscription plan deleted successfully.'];
    }

    public function restorePlan(string $uuid): array
    {
        $plan = $this->repo->findTrashedByUuid($uuid);

        if (! $plan) {
            return ['success' => false, 'message' => 'Subscription plan not found in trash.'];
        }

        $plan = $this->repo->restore($plan);

        Log::channel('admin')->info('Subscription plan restored.', ['plan_uuid' => $plan->uuid]);

        return [
            'success' => true,
            'message' => 'Subscription plan restored successfully.',
            'plan'    => new SubscriptionPlanResource($plan),
        ];
    }

    public function listActivePlans(int $perPage = 15)
    {
        $plans = $this->repo->listActive($perPage);

        return $plans->through(fn ($plan) => new SubscriptionPlanResource($plan));
    }

    public function getActivePlan(string $uuid): array
    {
        $plan = $this->repo->findActiveByUuid($uuid);

        if (! $plan) {
            return ['success' => false, 'message' => 'Subscription plan not found.'];
        }

        return ['success' => true, 'plan' => new SubscriptionPlanResource($plan)];
    }
}