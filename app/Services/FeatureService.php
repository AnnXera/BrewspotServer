<?php

namespace App\Services;

use App\Http\Resources\FeatureResource;
use App\Repository\FeatureRepository;
use Illuminate\Support\Facades\Log;

class FeatureService
{
    public function __construct(
        private readonly FeatureRepository $repo
    ) {}

    public function listFeatures(int $perPage = 50)
    {
        Log::channel('admin')->info('Admin listed system features.', [
            'per_page' => $perPage,
        ]);

        $features = $this->repo->list($perPage);

        return $features->through(fn ($feature) => new FeatureResource($feature));
    }

    public function listActiveFeatures()
    {
        $features = $this->repo->allActive();

        return FeatureResource::collection($features);
    }

    public function createFeature(array $payload): array
    {
        $feature = $this->repo->create($payload);

        Log::channel('admin')->info('System feature created.', [
            'feature_uuid' => $feature->uuid,
            'key'          => $feature->key,
            'name'         => $feature->name,
        ]);

        return [
            'success' => true,
            'message' => 'Feature created successfully.',
            'feature' => new FeatureResource($feature),
        ];
    }

    public function updateFeature(string $uuid, array $payload): array
    {
        $feature = $this->repo->findByUuid($uuid);

        if (! $feature) {
            Log::channel('admin')->warning('Feature update failed — not found.', [
                'feature_uuid' => $uuid,
            ]);

            return ['success' => false, 'message' => 'Feature not found.'];
        }

        $feature = $this->repo->update($feature, $payload);

        Log::channel('admin')->info('System feature updated.', [
            'feature_uuid' => $feature->uuid,
            'name'         => $feature->name,
            'is_active'    => $feature->is_active,
        ]);

        return [
            'success' => true,
            'message' => 'Feature updated successfully.',
            'feature' => new FeatureResource($feature),
        ];
    }

    public function deleteFeature(string $uuid): array
    {
        $feature = $this->repo->findByUuid($uuid);

        if (! $feature) {
            Log::channel('admin')->warning('Feature delete failed — not found.', [
                'feature_uuid' => $uuid,
            ]);

            return ['success' => false, 'message' => 'Feature not found.'];
        }

        $this->repo->delete($feature);

        Log::channel('admin')->info('System feature deleted.', [
            'feature_uuid' => $uuid,
            'key'          => $feature->key,
        ]);

        return ['success' => true, 'message' => 'Feature deleted successfully.'];
    }
}
