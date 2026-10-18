<?php

namespace App\Repository;

use App\Models\MenuAddon;
use App\Models\MenuItem;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

class MenuAddonRepository
{
    private const DETAIL_RELATIONS = ['recipes.ingredient', 'items'];

    public function __construct(
        private readonly IngredientRepository $ingredients
    ) {}

    public function listForCafe(int $cafeId): Collection
    {
        return MenuAddon::where('cafe_id', $cafeId)
            ->with(self::DETAIL_RELATIONS)
            ->orderBy('name')
            ->get();
    }

    public function findByUuidForCafe(string $uuid, int $cafeId): ?MenuAddon
    {
        return MenuAddon::where('cafe_id', $cafeId)
            ->where('uuid', $uuid)
            ->with(self::DETAIL_RELATIONS)
            ->first();
    }

    /** @param array<int, string> $uuids */
    public function findByUuidsForCafe(int $cafeId, array $uuids): Collection
    {
        if (empty($uuids)) {
            return new Collection();
        }

        return MenuAddon::where('cafe_id', $cafeId)->whereIn('uuid', $uuids)->get();
    }

    /** Another add-on in the cafe with the same name, ignoring case. */
    public function findNameClash(int $cafeId, string $name, ?int $exceptId = null): ?MenuAddon
    {
        return MenuAddon::where('cafe_id', $cafeId)
            ->whereRaw('LOWER(name) = ?', [mb_strtolower(trim($name))])
            ->when($exceptId, fn ($q) => $q->where('addon_id', '!=', $exceptId))
            ->first();
    }

    /**
     * Ids of the cafe's menu items with these uuids.
     *
     * @param array<int, string> $uuids
     * @return array<int, int>
     */
    public function findItemIdsForCafe(int $cafeId, array $uuids): array
    {
        if (empty($uuids)) {
            return [];
        }

        return MenuItem::where('cafe_id', $cafeId)->whereIn('uuid', $uuids)->pluck('men_item_id')->all();
    }

    /**
     * @param array<int, array{ingredient: ?\App\Models\Ingredient, name: string, quantity: mixed, unit: string}> $recipes
     *        Already resolved by RecipeResolver.
     * @param ?array<int, int> $itemIds Menu items offering it; null = unchanged.
     */
    public function save(?MenuAddon $addon, int $cafeId, array $data, ?array $recipes, ?array $itemIds): MenuAddon
    {
        return DB::transaction(function () use ($addon, $cafeId, $data, $recipes, $itemIds) {
            $attributes = array_intersect_key($data, array_flip(['name', 'price', 'max_quantity', 'is_available']));

            if (isset($attributes['name'])) {
                $attributes['name'] = trim($attributes['name']);
            }

            if ($addon) {
                $addon->update($attributes);
            } else {
                $addon = MenuAddon::create([...$attributes, 'cafe_id' => $cafeId]);
            }

            if ($recipes !== null) {
                $addon->recipes()->delete();

                foreach ($recipes as $recipe) {
                    $ingredient = $recipe['ingredient']
                        ?? $this->ingredients->findOrCreate($cafeId, $recipe['name'], $recipe['unit']);

                    if (! $ingredient->is_active) {
                        $ingredient->update(['is_active' => true]);
                    }

                    $addon->recipes()->create([
                        'ingredient_id'   => $ingredient->ingredient_id,
                        'ingredient_name' => $ingredient->name,
                        'quantity'        => $recipe['quantity'],
                        'unit'            => $ingredient->unit,
                    ]);
                }
            }

            if ($itemIds !== null) {
                $addon->items()->sync($itemIds);
            }

            return $addon->fresh(self::DETAIL_RELATIONS);
        });
    }

    /**
     * Soft-deleted so past sales keep their link; taken off every item now.
     */
    public function delete(MenuAddon $addon): void
    {
        DB::transaction(function () use ($addon) {
            $addon->items()->detach();
            $addon->delete();
        });
    }
}
