<?php

namespace App\Services;

use App\Models\User;
use App\Repository\ItemBranchRepository;
use App\Repository\MenuItemRepository;
use Illuminate\Support\Facades\Log;

class ItemBranchService
{
    public function __construct(
        private readonly ItemBranchRepository $repo,
        private readonly MenuItemRepository $itemRepo
    ) {}

    public function setBranchAvailability(User $owner, string $itemUuid, string $branchUuid, bool $isAvailable): array
    {
        $cafe = $this->itemRepo->findCafeByOwner($owner->user_id);

        if (! $cafe) {
            return ['success' => false, 'message' => 'No cafe found for this account.'];
        }

        $item = $this->itemRepo->findByUuidForCafe($itemUuid, $cafe->cafe_id);

        if (! $item) {
            Log::channel('owner')->warning('Item branch override blocked — item not found or not owned.', [
                'owner_uuid' => $owner->uuid,
                'item_uuid'  => $itemUuid,
            ]);

            return ['success' => false, 'message' => 'Item not found.'];
        }

        $branch = $this->repo->findBranchByUuidForCafe($branchUuid, $cafe->cafe_id);

        if (! $branch) {
            Log::channel('owner')->warning('Item branch override blocked — branch not found or not owned.', [
                'owner_uuid'  => $owner->uuid,
                'branch_uuid' => $branchUuid,
            ]);

            return ['success' => false, 'message' => 'Branch not found.'];
        }

        $override = $this->repo->setAvailability($branch->branch_id, $item, $isAvailable);

        Log::channel('owner')->info('Item branch availability updated.', [
            'owner_uuid'   => $owner->uuid,
            'item_uuid'    => $item->uuid,
            'branch_uuid'  => $branch->uuid,
            'is_available' => $isAvailable,
        ]);

        return [
            'success' => true,
            'message' => 'Branch availability updated successfully.',
            'branch'  => [
                'branch_uuid'  => $branch->uuid,
                'branch_name'  => $branch->branch_name,
                'is_available' => $isAvailable,
                'has_override' => ItemBranchRepository::differsFromDefault($override, $item),
            ],
        ];
    }

    public function listAllBranchesStatus(User $owner, string $itemUuid): array
    {
        $cafe = $this->itemRepo->findCafeByOwner($owner->user_id);

        if (! $cafe) {
            return ['success' => false, 'message' => 'No cafe found for this account.'];
        }

        $item = $this->itemRepo->findByUuidForCafe($itemUuid, $cafe->cafe_id);

        if (! $item) {
            return ['success' => false, 'message' => 'Item not found.'];
        }

        return [
            'success'  => true,
            'item'     => [
                'uuid'         => $item->uuid,
                'name'         => $item->menu_name,
                'is_available' => $item->is_available,
            ],
            'branches' => $this->repo->listAllBranchesWithEffectiveAvailability($cafe->cafe_id, $item),
        ];
    }
}
