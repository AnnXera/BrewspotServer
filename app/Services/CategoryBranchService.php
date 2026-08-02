<?php

namespace App\Services;

use App\Http\Resources\CategoryBranchResource;
use App\Models\User;
use App\Repository\CategoryBranchRepository;
use App\Repository\MenuCategoryRepository;
use Illuminate\Support\Facades\Log;

class CategoryBranchService
{
    public function __construct(
        private readonly CategoryBranchRepository $repo,
        private readonly MenuCategoryRepository $categoryRepo
    ) {}

    public function setBranchAvailability(User $owner, string $categoryUuid, string $branchUuid, bool $isAvailable): array
    {
        $cafe = $this->categoryRepo->findCafeByOwner($owner->user_id);

        if (! $cafe) {
            return ['success' => false, 'message' => 'No cafe found for this account.'];
        }

        $category = $this->categoryRepo->findByUuidForCafe($categoryUuid, $cafe->cafe_id);

        if (! $category) {
            Log::channel('owner')->warning('Category branch override blocked — category not found or not owned.', [
                'owner_uuid'    => $owner->uuid,
                'category_uuid' => $categoryUuid,
            ]);

            return ['success' => false, 'message' => 'Category not found.'];
        }

        $branch = $this->repo->findBranchByUuidForCafe($branchUuid, $cafe->cafe_id);

        if (! $branch) {
            Log::channel('owner')->warning('Category branch override blocked — branch not found or not owned.', [
                'owner_uuid'  => $owner->uuid,
                'branch_uuid' => $branchUuid,
            ]);

            return ['success' => false, 'message' => 'Branch not found.'];
        }

        $override = $this->repo->upsert($branch->branch_id, $category->men_category_id, $isAvailable);

        Log::channel('owner')->info('Category branch availability updated.', [
            'owner_uuid'    => $owner->uuid,
            'category_uuid' => $category->uuid,
            'branch_uuid'   => $branch->uuid,
            'is_available'  => $isAvailable,
        ]);

        return [
            'success'  => true,
            'message'  => 'Branch availability updated successfully.',
            'override' => new CategoryBranchResource($override->load(['branch', 'category'])),
        ];
    }

    public function listBranchOverrides(User $owner, string $categoryUuid): array
    {
        $cafe = $this->categoryRepo->findCafeByOwner($owner->user_id);

        if (! $cafe) {
            return ['success' => false, 'message' => 'No cafe found for this account.'];
        }

        $category = $this->categoryRepo->findByUuidForCafe($categoryUuid, $cafe->cafe_id);

        if (! $category) {
            return ['success' => false, 'message' => 'Category not found.'];
        }

        $overrides = $this->repo->listByCategory($category->men_category_id);

        return [
            'success'   => true,
            'overrides' => CategoryBranchResource::collection($overrides),
        ];
    }

    public function listAllBranchesStatus(User $owner, string $categoryUuid): array
    {
        $cafe = $this->categoryRepo->findCafeByOwner($owner->user_id);

        if (! $cafe) {
            return ['success' => false, 'message' => 'No cafe found for this account.'];
        }

        $category = $this->categoryRepo->findByUuidForCafe($categoryUuid, $cafe->cafe_id);

        if (! $category) {
            return ['success' => false, 'message' => 'Category not found.'];
        }

        $branches = $this->repo->listAllBranchesWithEffectiveAvailability($cafe->cafe_id, $category);

        return [
            'success'  => true,
            'category' => [
                'uuid'         => $category->uuid,
                'name'         => $category->name,
                'is_available' => $category->is_available,
            ],
            'branches' => $branches,
        ];
    }
}