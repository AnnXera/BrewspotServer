<?php

namespace App\Repository;

use App\Models\Cafe;
use App\Models\Ingredient;
use App\Models\MenuCategory;
use App\Models\MenuItem;
use Illuminate\Support\Facades\DB;

class MenuItemRepository
{
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
     * @param array<int, array{ingredient: ?Ingredient, name: string, quantity: mixed, unit: string}> $recipes
     *        Already resolved by MenuItemService::resolveRecipes().
     */
    public function create(int $cafeId, ?int $categoryId, array $payload, array $recipes): MenuItem
    {
        return DB::transaction(function () use ($cafeId, $categoryId, $payload, $recipes) {
            $item = MenuItem::create([
                'cafe_id'         => $cafeId,
                'men_category_id' => $categoryId,
                'menu_name'       => $payload['menu_name'],
                'description'     => $payload['description'] ?? null,
                'base_price'      => $payload['base_price'],
                'is_available'    => true, // automatically true when created
                'picture'         => $payload['picture'] ?? null,
            ]);

            $this->saveRecipes($item, $recipes);

            return $item->load('recipes.ingredient');
        });
    }

    public function findByUuidForCafe(string $uuid, int $cafeId): ?MenuItem
    {
        return MenuItem::where('uuid', $uuid)
            ->where('cafe_id', $cafeId)
            ->with('recipes.ingredient')
            ->first();
    }

    public function update(MenuItem $item, array $payload, ?array $recipes = null): MenuItem
    {
        return DB::transaction(function () use ($item, $payload, $recipes) {
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

            if ($recipes !== null) {
                // Delete old recipes and insert new ones
                $item->recipes()->delete();
                $this->saveRecipes($item, $recipes);
            }

            return $item->fresh('recipes.ingredient');
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

        return $query->with(['category', 'recipes.ingredient'])
            ->orderBy('menu_name')
            ->get();
    }

    /**
     * New ingredient names are added to the cafe's list here, inside the
     * caller's transaction, so a failed save leaves no orphan ingredients.
     */
    private function saveRecipes(MenuItem $item, array $recipes): void
    {
        foreach ($recipes as $recipe) {
            $ingredient = $recipe['ingredient']
                ?? $this->ingredients->findOrCreate($item->cafe_id, $recipe['name'], $recipe['unit']);

            // Used again in a recipe → back on the picker list.
            if (! $ingredient->is_active) {
                $ingredient->update(['is_active' => true]);
            }

            $item->recipes()->create([
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
