<?php

namespace App\Repository;

use App\Models\SubscriptionPlan;

class SubscriptionPlanRepository
{
    public function list(int $perPage = 15)
    {
        return SubscriptionPlan::latest()->paginate($perPage);
    }

    public function findByUuid(string $uuid): ?SubscriptionPlan
    {
        return SubscriptionPlan::where('uuid', $uuid)->first();
    }

    public function findTrashedByUuid(string $uuid): ?SubscriptionPlan
    {
        return SubscriptionPlan::onlyTrashed()->where('uuid', $uuid)->first();
    }

    public function listActive(int $perPage = 15)
    {
        return SubscriptionPlan::where('is_active', true)
            ->latest()
            ->paginate($perPage);
    }

    public function findActiveByUuid(string $uuid): ?SubscriptionPlan
    {
        return SubscriptionPlan::where('uuid', $uuid)
            ->where('is_active', true)
            ->first();
    }

    public function create(array $payload): SubscriptionPlan
    {
        return SubscriptionPlan::create([
            'sub_name'      => $payload['sub_name'],
            'price'         => $payload['price'],
            'max_branches'  => $payload['max_branches'],
            'description'   => $payload['description'] ?? null,
            'duration_days' => $payload['duration_days'],
            'is_active'     => $payload['is_active'] ?? true,
        ]);
    }

    public function update(SubscriptionPlan $plan, array $payload): SubscriptionPlan
    {
        $plan->update(array_filter([
            'sub_name'      => $payload['sub_name'] ?? null,
            'price'         => $payload['price'] ?? null,
            'max_branches'  => $payload['max_branches'] ?? null,
            'description'   => $payload['description'] ?? null,
            'duration_days' => $payload['duration_days'] ?? null,
            'is_active'     => $payload['is_active'] ?? null,
        ], fn ($value) => $value !== null));

        return $plan->fresh();
    }

    public function delete(SubscriptionPlan $plan): void
    {
        $plan->delete();
    }

    public function restore(SubscriptionPlan $plan): SubscriptionPlan
    {
        $plan->restore();

        return $plan->fresh();
    }
}