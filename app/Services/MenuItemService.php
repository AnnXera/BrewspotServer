<?php

namespace App\Services;

use App\Http\Resources\MenuItemResource;
use App\Models\User;
use App\Repository\MenuAddonRepository;
use App\Repository\MenuItemRepository;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Http\UploadedFile;

class MenuItemService
{
    public function __construct(
        private readonly MenuItemRepository $repo,
        private readonly MenuAddonRepository $addons,
        private readonly RecipeResolver $recipeResolver
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

        $sizes   = $payload['sizes'] ?? [];
        $payload = $this->applySizePrice($payload, $sizes);
        $recipes = $this->recipeResolver->resolve($cafe->cafe_id, $payload['recipes'], count($sizes));

        if (isset($recipes['errors'])) {
            return $recipes;
        }

        $addonIds = $this->resolveAddonIds($cafe->cafe_id, $payload['addon_uuids'] ?? []);

        if (isset($addonIds['errors'])) {
            return $addonIds;
        }

        try {
            if (isset($payload['picture']) && $payload['picture'] instanceof UploadedFile) {
                $path = 'users/' . $owner->uuid . '/cafes/menu-item';
                $payload['picture'] = $payload['picture']->store($path, 'public');
            }

            $item = $this->repo->create($cafe->cafe_id, $category?->men_category_id, $payload, $sizes, $recipes, $addonIds['ids']);
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

        // Absent = unchanged; [] = the item no longer comes in sizes.
        $sizes   = array_key_exists('sizes', $payload) ? ($payload['sizes'] ?? []) : null;
        $payload = $this->applySizePrice($payload, $sizes);
        $recipes = null;

        if (isset($payload['recipes'])) {
            // size_index points into the sizes being saved, or the current ones.
            $sizeCount = $sizes !== null ? count($sizes) : $item->sizes->count();
            $recipes   = $this->recipeResolver->resolve($cafe->cafe_id, $payload['recipes'], $sizeCount);

            if (isset($recipes['errors'])) {
                return $recipes;
            }
        }

        $addonIds = null;

        if (array_key_exists('addon_uuids', $payload)) {
            $addonIds = $this->resolveAddonIds($cafe->cafe_id, $payload['addon_uuids'] ?? []);

            if (isset($addonIds['errors'])) {
                return $addonIds;
            }

            $addonIds = $addonIds['ids'];
        }

        try {
            if (isset($payload['picture']) && $payload['picture'] instanceof UploadedFile) {
                if ($item->picture) {
                    Storage::disk('public')->delete($item->picture);
                }
                $path = 'users/' . $owner->uuid . '/cafes/menu-item';
                $payload['picture'] = $payload['picture']->store($path, 'public');
            }

            $item = $this->repo->update($item, $payload, $sizes, $recipes, $addonIds);
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

    /**
     * Sizes replace the single price: base_price becomes the lowest size
     * price, which menus show as the starting price.
     */
    private function applySizePrice(array $payload, ?array $sizes): array
    {
        if ($sizes) {
            $payload['base_price'] = min(array_map('floatval', array_column($sizes, 'price')));
        }

        return $payload;
    }

    /**
     * Ids of the cafe's add-ons with these uuids, or a 422 when one is unknown.
     */
    private function resolveAddonIds(int $cafeId, array $uuids): array
    {
        $uuids  = array_values(array_unique($uuids));
        $addons = $this->addons->findByUuidsForCafe($cafeId, $uuids);

        if ($addons->count() !== count($uuids)) {
            return ['success' => false, 'http' => 422, 'message' => 'Validation failed.', 'errors' => [
                'addon_uuids' => ['An add-on you picked no longer exists. Pick the add-ons again.'],
            ]];
        }

        return ['ids' => $addons->pluck('addon_id')->all()];
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
