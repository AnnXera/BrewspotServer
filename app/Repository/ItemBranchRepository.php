<?php

namespace App\Repository;

use App\Models\CafeBranch;
use App\Models\MenuBranch;
use App\Models\MenuItem;

class ItemBranchRepository
{
    public function findBranchByUuidForCafe(string $uuid, int $cafeId): ?CafeBranch
    {
        return CafeBranch::where('uuid', $uuid)
            ->where('cafe_id', $cafeId)
            ->first();
    }

    /**
     * Rows only exist for exceptions: matching the item default removes the override,
     * unless the row also carries a branch price, in which case it is kept.
     */
    public function setAvailability(int $branchId, MenuItem $item, bool $isAvailable): ?MenuBranch
    {
        $override = MenuBranch::where('branch_id', $branchId)
            ->where('men_item_id', $item->men_item_id)
            ->first();

        if ($isAvailable === (bool) $item->is_available) {
            if (! $override) {
                return null;
            }

            if ($override->branch_price === null) {
                $override->delete();

                return null;
            }
        }

        return MenuBranch::updateOrCreate(
            ['branch_id' => $branchId, 'men_item_id' => $item->men_item_id],
            ['is_available' => $isAvailable]
        );
    }

    /**
     * Every branch under the cafe, with effective (inherited or overridden) availability.
     */
    public function listAllBranchesWithEffectiveAvailability(int $cafeId, MenuItem $item)
    {
        $branches = CafeBranch::where('cafe_id', $cafeId)->get();

        $overrides = MenuBranch::where('men_item_id', $item->men_item_id)
            ->get()
            ->keyBy('branch_id');

        return $branches->map(function ($branch) use ($overrides, $item) {
            $override = $overrides->get($branch->branch_id);

            return [
                'branch_uuid'  => $branch->uuid,
                'branch_name'  => $branch->branch_name,
                'is_available' => $override?->is_available ?? $item->is_available,
                'has_override' => self::differsFromDefault($override, $item),
            ];
        });
    }

    public static function differsFromDefault(?MenuBranch $override, MenuItem $item): bool
    {
        return $override !== null && $override->is_available !== (bool) $item->is_available;
    }
}
