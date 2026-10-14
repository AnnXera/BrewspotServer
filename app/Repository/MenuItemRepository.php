<?php

namespace App\Repository;

use App\Models\Cafe;
use App\Models\Ingredient;
use App\Models\MenuCategory;
use App\Models\MenuItem;
use App\Models\MenuItemSize;
use Illuminate\Support\Facades\DB;

class MenuItemRepository
{
    /** What the menu pages and the item modal need for each item. */
    public const DETAIL_RELATIONS = ['sizes', 'addons', 'recipes.ingredient', 'recipes.size'];

    public function __construct(
        private readonly IngredientRepository $ingredients
    ) {}

    public function findCafeByOwner(int $userId): ?Cafe
    {
        return Cafe::where('user_id', $userId)->first();
    }

    public function findCategoryByUuidForCafe(string $uuid, int $cafeId): ?MenuCategory
    {
        return MenuCategory::where('uuid', $uuid)
            ->where('cafe_id', $cafeId)
            ->first();
    }

    /**
     * @param array<int, array{uuid?: ?string, name: string, price: mixed}> $sizes In display order; [] = no sizes.
     * @param array<int, array{ingredient: ?Ingredient, name: string, quantity: mixed, unit: string, size_index: ?int}> $recipes
     *        Already resolved by RecipeResolver.
     * @param array<int, int> $addonIds
     */
    public function create(int $cafeId, ?int $categoryId, array $payload, array $sizes, array $recipes, array $addonIds): MenuItem
    {
        return DB::transaction(function () use ($cafeId, $categoryId, $payload, $sizes, $recipes, $addonIds) {
            $item = MenuItem::create([
                'cafe_id'         => $cafeId,
                'men_category_id' => $categoryId,
                'menu_name'       => $payload['menu_name'],
                'description'     => $payload['description'] ?? null,
                'base_price'      => $payload['base_price'],
                'is_available'    => true, // automatically true when created
                'picture'         => $payload['picture'] ?? null,
            ]);

            $savedSizes = $this->syncSizes($item, $sizes);
            $this->saveRecipes($item, $recipes, $savedSizes);
            $item->addons()->sync($addonIds);

            return $item->load(self::DETAIL_RELATIONS);
        });
    }

    public function findByUuidForCafe(string $uuid, int $cafeId): ?MenuItem
    {
        return MenuItem::where('uuid', $uuid)
            ->where('cafe_id', $cafeId)
            ->with(self::DETAIL_RELATIONS)
            ->first();
    }

    /**
     * Null $sizes, $recipes or $addonIds leave that part unchanged.
     */
    public function update(MenuItem $item, array $payload, ?array $sizes = null, ?array $recipes = null, ?array $addonIds = null): MenuItem
    {
        return DB::transaction(function () use ($item, $payload, $sizes, $recipes, $addonIds) {
            $updateData = array_filter([
                'menu_name'       => $payload['menu_name'] ?? null,
                'description'     => array_key_exists('description', $payload) ? $payload['description'] : null,
                'base_price'      => $payload['base_price'] ?? null,
                'is_available'    => array_key_exists('is_available', $payload) ? $payload['is_available'] : null,
                'picture'         => array_key_exists('picture', $payload) ? $payload['picture'] : null,
            ], fn ($value) => $value !== null);

            // Null is meaningful here (uncategorized), so it can't go through the filter above.
            if (array_key_exists('men_category_id', $payload)) {
                $updateData['men_category_id'] = $payload['men_category_id'];
            }

            if (!empty($updateData)) {
                $item->update($updateData);
            }

            // Removing a size also removes its own recipe rows (FK cascade).
            $savedSizes = $sizes !== null
                ? $this->syncSizes($item, $sizes)
                : $item->sizes()->get()->values()->all();

            if ($recipes !== null) {
                // Delete old recipes and insert new ones
                $item->recipes()->delete();
                $this->saveRecipes($item, $recipes, $savedSizes);
            }

            if ($addonIds !== null) {
                $item->addons()->sync($addonIds);
            }

            return $item->fresh(self::DETAIL_RELATIONS);
        });
    }

    public function listByCafe(int $cafeId, ?string $categoryUuid = null)
    {
        $query = MenuItem::where('cafe_id', $cafeId);

        if ($categoryUuid === 'uncategorized') {
            $query->whereNull('men_category_id');
        } elseif ($categoryUuid) {
            $query->whereHas('category', function ($q) use ($categoryUuid) {
                $q->where('uuid', $categoryUuid);
            });
        }

        return $query->with(['category', ...self::DETAIL_RELATIONS])
            ->orderBy('menu_name')
            ->get();
    }

    /**
     * Sizes sent back with their uuid keep their row, so past sales still
     * point at them; sizes left out are deleted.
     *
     * @return array<int, MenuItemSize> The saved sizes, in the order given.
     */
    private function syncSizes(MenuItem $item, array $sizes): array
    {
        $existing = MenuItemSize::where('men_item_id', $item->men_item_id)->get()->keyBy('uuid');
        $saved    = [];

        foreach (array_values($sizes) as $i => $row) {
            $attributes = ['name' => trim($row['name']), 'price' => $row['price'], 'sort_order' => $i];
            $size       = $existing->get($row['uuid'] ?? '');

            if ($size) {
                $size->update($attributes);
            } else {
                $size = MenuItemSize::create([...$attributes, 'men_item_id' => $item->men_item_id]);
            }

            $saved[$i] = $size;
        }

        MenuItemSize::where('men_item_id', $item->men_item_id)
            ->whereNotIn('men_size_id', array_map(fn ($s) => $s->men_size_id, $saved))
            ->delete();

        return $saved;
    }

    /**
     * New ingredient names are added to the cafe's list here, inside the
     * caller's transaction, so a failed save leaves no orphan ingredients.
     *
     * @param array<int, MenuItemSize> $sizes What each row's size_index points at.
     */
    private function saveRecipes(MenuItem $item, array $recipes, array $sizes = []): void
    {
        foreach ($recipes as $recipe) {
            $ingredient = $recipe['ingredient']
                ?? $this->ingredients->findOrCreate($item->cafe_id, $recipe['name'], $recipe['unit']);

            // Used again in a recipe → back on the picker list.
            if (! $ingredient->is_active) {
                $ingredient->update(['is_active' => true]);
            }

            $item->recipes()->create([
                'men_size_id'     => isset($recipe['size_index']) ? $sizes[$recipe['size_index']]->men_size_id : null,
                'ingredient_id'   => $ingredient->ingredient_id,
                'ingredient_name' => $ingredient->name,
                'quantity'        => $recipe['quantity'],
                'unit'            => $ingredient->unit,
            ]);
        }
    }

    public function delete(MenuItem $item): void
    {
        $item->delete();
    }
}
