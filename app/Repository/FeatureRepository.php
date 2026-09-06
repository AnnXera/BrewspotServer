<?php

namespace App\Repository;

use App\Models\Feature;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;

class FeatureRepository
{
    public function list(int $perPage = 50): LengthAwarePaginator
    {
        return Feature::orderBy('name')->paginate($perPage);
    }

    public function allActive(): Collection
    {
        return Feature::where('is_active', true)->orderBy('name')->get();
    }

    public function findByUuid(string $uuid): ?Feature
    {
        return Feature::where('uuid', $uuid)->first();
    }

    public function findByKey(string $key): ?Feature
    {
        return Feature::where('key', $key)->first();
    }

    public function create(array $payload): Feature
    {
        return Feature::create([
            'key'         => $payload['key'],
            'name'        => $payload['name'],
            'description' => $payload['description'] ?? null,
            'is_active'   => $payload['is_active'] ?? true,
        ]);
    }

    public function update(Feature $feature, array $payload): Feature
    {
        $feature->update(array_filter([
            'name'        => $payload['name'] ?? null,
            'description' => $payload['description'] ?? null,
            'is_active'   => $payload['is_active'] ?? null,
        ], fn ($value) => $value !== null));

        return $feature->fresh();
    }

    public function delete(Feature $feature): void
    {
        $feature->delete();
    }
}
