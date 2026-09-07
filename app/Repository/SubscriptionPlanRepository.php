<?php

namespace App\Repository;

use App\Models\Feature;
use App\Models\SubscriptionPlan;

class SubscriptionPlanRepository
{
    public function list(int $perPage = 15)
    {
        return SubscriptionPlan::with('features')->latest()->paginate($perPage);
    }

    public function findByUuid(string $uuid): ?SubscriptionPlan
    {
        return SubscriptionPlan::with('features')->where('uuid', $uuid)->first();
    }

    public function findTrashedByUuid(string $uuid): ?SubscriptionPlan
    {
        return SubscriptionPlan::onlyTrashed()->with('features')->where('uuid', $uuid)->first();
    }

    public function listActive(int $perPage = 15)
    {
        return SubscriptionPlan::where('is_active', true)->with('features')->latest()->paginate($perPage);
    }

    public function findActiveByUuid(string $uuid): ?SubscriptionPlan
    {
        return SubscriptionPlan::where('uuid', $uuid)->where('is_active', true)->with('features')->first();
    }

    public function create(array $payload): SubscriptionPlan
    {
        $plan = SubscriptionPlan::create([
            'sub_name'      => $payload['sub_name'],
            'price'         => $payload['price'],
            'yearly_price'  => $payload['yearly_price'] ?? 0.00,
            'max_branches'  => $payload['max_branches'],
            'description'   => $payload['description'] ?? null,
            'duration_days' => $payload['duration_days'],
            'is_active'     => $payload['is_active'] ?? true,
        ]);

        if (! empty($payload['features']) && is_array($payload['features'])) {
            $featureIds = Feature::whereIn('key', $payload['features'])->pluck('feature_id');
            $plan->features()->sync($featureIds);
        }

        return $plan->fresh(['features']);
    }

    public function update(SubscriptionPlan $plan, array $payload): SubscriptionPlan
    {
        $plan->update(array_filter([
            'sub_name'      => $payload['sub_name'] ?? null,
            'price'         => $payload['price'] ?? null,
            'yearly_price'  => array_key_exists('yearly_price', $payload) ? $payload['yearly_price'] : null,
            'max_branches'  => $payload['max_branches'] ?? null,
            'description'   => $payload['description'] ?? null,
            'duration_days' => $payload['duration_days'] ?? null,
            'is_active'     => $payload['is_active'] ?? null,
        ], fn ($value) => $value !== null));

        if (array_key_exists('features', $payload) && is_array($payload['features'])) {
            $featureIds = Feature::whereIn('key', $payload['features'])->pluck('feature_id');
            $plan->features()->sync($featureIds);
        }

        return $plan->fresh(['features']);
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

    public function syncPayPalPlanId(SubscriptionPlan $plan, string $paypalPlanId): SubscriptionPlan
    {
        $plan->update(['paypal_plan_id' => $paypalPlanId]);

        return $plan->fresh();
    }
}