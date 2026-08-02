<?php

namespace App\Repository;

use App\Models\CafeBranch;
use App\Models\CategoryBranch;
use App\Models\MenuCategory;

class CategoryBranchRepository
{
    public function findBranchByUuidForCafe(string $uuid, int $cafeId): ?CafeBranch
    {
        return CafeBranch::where('uuid', $uuid)
            ->where('cafe_id', $cafeId)
            ->first();
    }

    public function upsert(int $branchId, int $categoryId, bool $isAvailable): CategoryBranch
    {
        return CategoryBranch::updateOrCreate(
            ['branch_id' => $branchId, 'men_category_id' => $categoryId],
            ['is_available' => $isAvailable]
        );
    }

    public function listByCategory(int $categoryId)
    {
        return CategoryBranch::where('men_category_id', $categoryId)
            ->with('branch')
            ->get();
    }

    /**
     * Effective availability for a category at a specific branch:
     * branch override if one exists, else the cafe-wide category default.
     */
    public function findEffectiveAvailability(int $branchId, MenuCategory $category): bool
    {
        $override = CategoryBranch::where('branch_id', $branchId)
            ->where('men_category_id', $category->men_category_id)
            ->first();

        return $override?->is_available ?? $category->is_available;
    }

    /**
     * Every branch under the cafe, with effective (inherited or overridden) availability.
     */
    public function listAllBranchesWithEffectiveAvailability(int $cafeId, MenuCategory $category)
    {
        $branches = CafeBranch::where('cafe_id', $cafeId)->get();

        $overrides = CategoryBranch::where('men_category_id', $category->men_category_id)
            ->get()
            ->keyBy('branch_id');

        return $branches->map(function ($branch) use ($overrides, $category) {
            $override = $overrides->get($branch->branch_id);

            return [
                'branch_uuid'  => $branch->uuid,
                'branch_name'  => $branch->branch_name,
                'is_available' => $override?->is_available ?? $category->is_available,
                'has_override' => $override !== null,
            ];
        });
    }
}