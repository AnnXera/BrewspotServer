<?php

namespace App\Services;

use App\Http\Resources\MenuItemResource;
use App\Models\Ingredient;
use App\Models\User;
use App\Repository\IngredientRepository;
use App\Repository\MenuItemRepository;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Http\UploadedFile;

class MenuItemService
{
    public function __construct(
        private readonly MenuItemRepository $repo,
        private readonly IngredientRepository $ingredients
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

        $recipes = $this->resolveRecipes($cafe->cafe_id, $payload['recipes']);

        if (isset($recipes['errors'])) {
            return $recipes;
        }

        try {
            if (isset($payload['picture']) && $payload['picture'] instanceof UploadedFile) {
                $path = 'users/' . $owner->uuid . '/cafes/menu-item';
                $payload['picture'] = $payload['picture']->store($path, 'public');
            }

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

        $recipes = null;

        if (isset($payload['recipes'])) {
            $recipes = $this->resolveRecipes($cafe->cafe_id, $payload['recipes']);

            if (isset($recipes['errors'])) {
                return $recipes;
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

    /**
     * Matches each recipe row to one of the cafe's ingredients (by uuid, or by
     * name ignoring case/spacing). Unmatched names become new ingredients when
     * the item is saved. Rejects the same ingredient twice in one recipe, and a
     * unit that differs from the ingredient's.
     *
     * @return array Resolved rows for MenuItemRepository, or a 422 result with 'errors'.
     */
    private function resolveRecipes(int $cafeId, array $recipes): array
    {
        $byUuid = $this->ingredients->findByUuids(
            $cafeId, array_values(array_filter(array_column($recipes, 'ingredient_uuid')))
        )->keyBy('uuid');

        $byName = $this->ingredients->findByNormalizedNames(
            $cafeId, array_map(fn ($n) => Ingredient::normalize($n), array_filter(array_column($recipes, 'ingredient_name')))
        )->keyBy('normalized_name');

        $resolved = [];
        $seen     = [];
        $errors   = [];

        foreach (array_values($recipes) as $i => $row) {
            if (! empty($row['ingredient_uuid'])) {
                $ingredient = $byUuid->get($row['ingredient_uuid']);

                if (! $ingredient) {
                    $errors["recipes.$i.ingredient_uuid"][] = 'That ingredient no longer exists. Pick it again.';
                    continue;
                }
            } else {
                $ingredient = $byName->get(Ingredient::normalize($row['ingredient_name']));
            }

            $name = $ingredient?->name ?? Ingredient::tidy($row['ingredient_name']);
            $key  = $ingredient ? "id:{$ingredient->ingredient_id}" : 'new:' . Ingredient::normalize($name);

            if (isset($seen[$key])) {
                $errors["recipes.$i.ingredient_name"][] = "{$name} is already in this recipe.";
                continue;
            }
            $seen[$key] = true;

            if ($ingredient && $ingredient->unit !== $row['unit']) {
                $errors["recipes.$i.unit"][] = "{$ingredient->name} is measured in {$ingredient->unit}.";
                continue;
            }

            $resolved[] = [
                'ingredient' => $ingredient,
                'name'       => $name,
                'quantity'   => $row['quantity'],
                'unit'       => $row['unit'],
            ];
        }

        if ($errors) {
            return ['success' => false, 'http' => 422, 'message' => 'Validation failed.', 'errors' => $errors];
        }

        return $resolved;
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
