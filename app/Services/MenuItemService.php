<?php

namespace App\Services;

use App\Http\Resources\MenuItemResource;
use App\Models\ActivityLog;
use App\Models\MenuItem;
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
        private readonly RecipeResolver $recipeResolver,
        private readonly ActivityLogService $activity
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
        $payload = $this->applySizePrice($payload, $sizes, $payload['is_hot_and_iced'] ?? false);
        $recipes = $this->recipeResolver->resolve($cafe->cafe_id, $payload['recipes'], array_column($sizes, 'name'));

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

        $this->activity->menu($owner, $cafe->cafe_id, null, ActivityLog::MENU_ITEM_CREATED,
            "Added menu item \"{$item->menu_name}\"" . ($category ? " to {$category->name}" : ''),
            ['item' => $item->menu_name, 'category' => $category?->name, 'base_price' => (float) $item->base_price]
        );

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

        $before = [
            'menu_name'    => $item->menu_name,
            'base_price'   => (float) $item->base_price,
            'category'     => $item->category?->name,
            'is_available' => (bool) $item->is_available,
        ];

        // Absent = unchanged; [] = the item no longer comes in sizes.
        $sizes   = array_key_exists('sizes', $payload) ? ($payload['sizes'] ?? []) : null;
        $payload = $this->applySizePrice($payload, $sizes, $payload['is_hot_and_iced'] ?? $item->is_hot_and_iced);
        $recipes = null;

        if (isset($payload['recipes'])) {
            // size_index points into the sizes being saved, or the current ones.
            $sizeNames = $sizes !== null ? array_column($sizes, 'name') : $item->sizes->pluck('name')->all();
            $recipes   = $this->recipeResolver->resolve($cafe->cafe_id, $payload['recipes'], $sizeNames);

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

        $this->logItemUpdate($owner, $cafe->cafe_id, $item, $before, $payload);

        return [
            'success' => true,
            'message' => 'Menu item updated successfully.',
            'item'    => new MenuItemResource($item),
        ];
    }

    /**
     * One audit log entry per edit: name, price, category and on/off keep before and after;
     * sizes, recipe, add-ons and picture are named when the form sent them.
     */
    private function logItemUpdate(User $owner, int $cafeId, MenuItem $item, array $before, array $payload): void
    {
        $item->load('category');

        $after = [
            'menu_name'    => $item->menu_name,
            'base_price'   => (float) $item->base_price,
            'category'     => $item->category?->name,
            'is_available' => (bool) $item->is_available,
        ];

        $parts   = [];
        $changes = [];

        if ($after['menu_name'] !== $before['menu_name']) {
            $parts[] = "renamed from \"{$before['menu_name']}\"";
        }
        if ($after['base_price'] !== $before['base_price']) {
            $parts[] = 'price ' . number_format($before['base_price'], 2) . ' to ' . number_format($after['base_price'], 2);
        }
        if ($after['category'] !== $before['category']) {
            $parts[] = 'moved to ' . ($after['category'] ?? 'Uncategorized');
        }
        if ($after['is_available'] !== $before['is_available']) {
            $parts[] = $after['is_available'] ? 'switched on' : 'switched off';
        }

        foreach (['menu_name', 'base_price', 'category', 'is_available'] as $field) {
            if ($after[$field] !== $before[$field]) {
                $changes[$field] = ['before' => $before[$field], 'after' => $after[$field]];
            }
        }

        $also = array_keys(array_filter([
            'sizes'   => array_key_exists('sizes', $payload),
            'recipe'  => isset($payload['recipes']),
            'add-ons' => array_key_exists('addon_uuids', $payload),
            'picture' => isset($payload['picture']),
        ]));

        $description = "Edited menu item \"{$item->menu_name}\"" . ($parts ? ': ' . implode(', ', $parts) : '');

        $this->activity->menu($owner, $cafeId, null, ActivityLog::MENU_ITEM_UPDATED, $description, [
            'item'    => $item->menu_name,
            'changes' => $changes ?: null,
            'saved'   => $also ?: null,
        ]);
    }

    /**
     * Sizes replace the single price: base_price becomes the lowest size
     * price (hot or iced), which menus show as the starting price.
     */
    private function applySizePrice(array $payload, ?array $sizes, bool $hotAndIced): array
    {
        if ($sizes) {
            $prices = array_column($sizes, 'price');
            if ($hotAndIced) {
                $prices = [...$prices, ...array_column($sizes, 'iced_price')];
            }
            $payload['base_price'] = min(array_map('floatval', $prices));
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

        $this->activity->menu($owner, $cafe->cafe_id, null, ActivityLog::MENU_ITEM_DELETED,
            "Deleted menu item \"{$item->menu_name}\"",
            ['item' => $item->menu_name]
        );

        return [
            'success' => true,
            'message' => 'Menu item deleted successfully.',
        ];
    }
}
