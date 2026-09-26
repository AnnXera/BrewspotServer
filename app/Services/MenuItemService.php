<?php

namespace App\Services;

use App\Http\Resources\MenuItemResource;
use App\Models\User;
use App\Repository\MenuItemRepository;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Http\UploadedFile;

class MenuItemService
{
    public function __construct(
        private readonly MenuItemRepository $repo
    ) {}

    public function createItem(User $owner, array $payload): array
    {
        $cafe = $this->repo->findCafeByOwner($owner->user_id);

        if (! $cafe) {
            Log::channel('owner')->warning('Menu item creation blocked — owner has no cafe.', [
                'owner_uuid' => $owner->uuid,
            ]);

            return ['success' => false, 'message' => 'No cafe found for this account.'];
        }

        $category = null;
        if (!empty($payload['category_uuid'])) {
            $category = $this->repo->findCategoryByUuidForCafe($payload['category_uuid'], $cafe->cafe_id);

            if (! $category) {
                return ['success' => false, 'message' => 'Invalid category or category does not belong to your cafe.'];
            }
        }

        try {
            if (isset($payload['picture']) && $payload['picture'] instanceof UploadedFile) {
                $path = 'users/' . $owner->uuid . '/cafes/menu-item';
                $payload['picture'] = $payload['picture']->store($path, 'public');
            }

            $recipes = $payload['recipes'];
            $item = $this->repo->create($cafe->cafe_id, $category?->men_category_id, $payload, $recipes);
        } catch (\Exception $e) {
            Log::channel('owner')->error('Failed to create menu item.', [
                'owner_uuid' => $owner->uuid,
                'error'      => $e->getMessage(),
            ]);

            throw $e;
        }

        Log::channel('owner')->info('Menu item created.', [
            'owner_uuid' => $owner->uuid,
            'item_uuid'  => $item->uuid,
            'menu_name'  => $item->menu_name,
        ]);

        return [
            'success' => true,
            'message' => 'Menu item created successfully.',
            'item'    => new MenuItemResource($item),
        ];
    }

    public function updateItem(User $owner, string $uuid, array $payload): array
    {
        $cafe = $this->repo->findCafeByOwner($owner->user_id);

        if (! $cafe) {
            return ['success' => false, 'message' => 'No cafe found for this account.'];
        }

        $item = $this->repo->findByUuidForCafe($uuid, $cafe->cafe_id);

        if (! $item) {
            Log::channel('owner')->warning('Menu item update blocked — not found or not owned.', [
                'owner_uuid' => $owner->uuid,
                'item_uuid'  => $uuid,
            ]);

            return ['success' => false, 'message' => 'Item not found.'];
        }

        if (array_key_exists('category_uuid', $payload)) {
            if ($payload['category_uuid'] === null) {
                $payload['men_category_id'] = null;
            } else {
                $category = $this->repo->findCategoryByUuidForCafe($payload['category_uuid'], $cafe->cafe_id);
                if (! $category) {
                    return ['success' => false, 'message' => 'Invalid category or category does not belong to your cafe.'];
                }
                $payload['men_category_id'] = $category->men_category_id;
            }
        }

        try {
            if (isset($payload['picture']) && $payload['picture'] instanceof UploadedFile) {
                if ($item->picture) {
                    Storage::disk('public')->delete($item->picture);
                }
                $path = 'users/' . $owner->uuid . '/cafes/menu-item';
                $payload['picture'] = $payload['picture']->store($path, 'public');
            }

            $recipes = $payload['recipes'] ?? null;
            $item = $this->repo->update($item, $payload, $recipes);
        } catch (\Exception $e) {
            Log::channel('owner')->error('Failed to update menu item.', [
                'owner_uuid' => $owner->uuid,
                'error'      => $e->getMessage(),
            ]);

            throw $e;
        }

        Log::channel('owner')->info('Menu item updated.', [
            'owner_uuid' => $owner->uuid,
            'item_uuid'  => $item->uuid,
        ]);

        return [
            'success' => true,
            'message' => 'Menu item updated successfully.',
            'item'    => new MenuItemResource($item),
        ];
    }

    public function listItems(User $owner, ?string $categoryUuid = null): array
    {
        $cafe = $this->repo->findCafeByOwner($owner->user_id);

        if (! $cafe) {
            return ['success' => false, 'message' => 'No cafe found for this account.'];
        }

        $items = $this->repo->listByCafe($cafe->cafe_id, $categoryUuid);

        return [
            'success' => true,
            'items'   => MenuItemResource::collection($items),
        ];
    }

    public function getItem(User $owner, string $uuid): array
    {
        $cafe = $this->repo->findCafeByOwner($owner->user_id);

        if (! $cafe) {
            return ['success' => false, 'message' => 'No cafe found for this account.'];
        }

        $item = $this->repo->findByUuidForCafe($uuid, $cafe->cafe_id);

        if (! $item) {
            return ['success' => false, 'message' => 'Item not found.'];
        }

        return [
            'success' => true,
            'item'    => new MenuItemResource($item->load('category')),
        ];
    }

    public function deleteItem(User $owner, string $uuid): array
    {
        $cafe = $this->repo->findCafeByOwner($owner->user_id);

        if (! $cafe) {
            return ['success' => false, 'message' => 'No cafe found for this account.'];
        }

        $item = $this->repo->findByUuidForCafe($uuid, $cafe->cafe_id);

        if (! $item) {
            Log::channel('owner')->warning('Menu item deletion blocked — not found or not owned.', [
                'owner_uuid' => $owner->uuid,
                'item_uuid'  => $uuid,
            ]);

            return ['success' => false, 'message' => 'Item not found.'];
        }

        if ($item->picture) {
            Storage::disk('public')->delete($item->picture);
        }

        $this->repo->delete($item);

        Log::channel('owner')->info('Menu item deleted.', [
            'owner_uuid' => $owner->uuid,
            'item_uuid'  => $uuid,
        ]);

        return [
            'success' => true,
            'message' => 'Menu item deleted successfully.',
        ];
    }
}
