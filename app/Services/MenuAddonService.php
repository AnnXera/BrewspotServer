<?php

namespace App\Services;

use App\Http\Resources\MenuAddonResource;
use App\Models\User;
use App\Repository\MenuAddonRepository;
use App\Repository\MenuItemRepository;
use Illuminate\Support\Facades\Log;

/**
 * Add-ons (Oreo crumbles +20) for the cafe's menu items. Each has a price,
 * the most one drink can have, a recipe, and the items that offer it.
 */
class MenuAddonService
{
    public function __construct(
        private readonly MenuAddonRepository $repo,
        private readonly MenuItemRepository $menuRepo,
        private readonly RecipeResolver $recipeResolver
    ) {}

    public function listAddons(User $owner): array
    {
        $cafe = $this->menuRepo->findCafeByOwner($owner->user_id);

        if (! $cafe) {
            return ['success' => false, 'http' => 404, 'message' => 'No cafe found for this account.'];
        }

        return [
            'success' => true,
            'addons'  => MenuAddonResource::collection($this->repo->listForCafe($cafe->cafe_id)),
        ];
    }

    public function createAddon(User $owner, array $data): array
    {
        return $this->save($owner, null, $data);
    }

    public function updateAddon(User $owner, string $uuid, array $data): array
    {
        return $this->save($owner, $uuid, $data);
    }

    private function save(User $owner, ?string $uuid, array $data): array
    {
        $cafe = $this->menuRepo->findCafeByOwner($owner->user_id);

        if (! $cafe) {
            return ['success' => false, 'http' => 404, 'message' => 'No cafe found for this account.'];
        }

        $addon = null;

        if ($uuid) {
            $addon = $this->repo->findByUuidForCafe($uuid, $cafe->cafe_id);

            if (! $addon) {
                return ['success' => false, 'http' => 404, 'message' => 'Add-on not found.'];
            }
        }

        if (isset($data['name']) && ($clash = $this->repo->findNameClash($cafe->cafe_id, $data['name'], $addon?->addon_id))) {
            return [
                'success' => false,
                'http'    => 422,
                'message' => 'Validation failed.',
                'errors'  => ['name' => ["{$clash->name} is already an add-on."]],
            ];
        }

        $recipes = null;

        if (array_key_exists('recipes', $data)) {
            $recipes = $this->recipeResolver->resolve($cafe->cafe_id, $data['recipes'] ?? []);

            if (isset($recipes['errors'])) {
                return $recipes;
            }
        }

        $itemIds = null;

        if (array_key_exists('item_uuids', $data)) {
            $uuids   = array_values(array_unique($data['item_uuids'] ?? []));
            $itemIds = $this->repo->findItemIdsForCafe($cafe->cafe_id, $uuids);

            if (count($itemIds) !== count($uuids)) {
                return [
                    'success' => false,
                    'http'    => 422,
                    'message' => 'Validation failed.',
                    'errors'  => ['item_uuids' => ['A menu item you picked no longer exists. Pick the items again.']],
                ];
            }
        }

        if (array_key_exists('is_available', $data)) {
            $data['is_available'] = (bool) $data['is_available'];
        }

        $addon = $this->repo->save($addon, $cafe->cafe_id, $data, $recipes, $itemIds);

        Log::channel('owner')->info($uuid ? 'Add-on updated.' : 'Add-on created.', [
            'owner_uuid' => $owner->uuid,
            'addon_uuid' => $addon->uuid,
        ]);

        return [
            'success' => true,
            'message' => $uuid ? 'Add-on updated.' : 'Add-on created.',
            'addon'   => new MenuAddonResource($addon),
        ];
    }

    public function deleteAddon(User $owner, string $uuid): array
    {
        $cafe = $this->menuRepo->findCafeByOwner($owner->user_id);

        if (! $cafe) {
            return ['success' => false, 'http' => 404, 'message' => 'No cafe found for this account.'];
        }

        $addon = $this->repo->findByUuidForCafe($uuid, $cafe->cafe_id);

        if (! $addon) {
            return ['success' => false, 'http' => 404, 'message' => 'Add-on not found.'];
        }

        $this->repo->delete($addon);

        Log::channel('owner')->info('Add-on deleted.', [
            'owner_uuid' => $owner->uuid,
            'addon_uuid' => $uuid,
        ]);

        return ['success' => true, 'message' => 'Add-on deleted.'];
    }
}
