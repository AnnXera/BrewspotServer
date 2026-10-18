<?php

namespace App\Repository;

use App\Models\Ingredient;
use App\Models\MenuAddonRecipe;
use App\Models\MenuRecipe;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

class IngredientRepository
{
    /**
     * An item with sizes has one recipe row per size, so items are counted
     * once each. Add-ons have one row per ingredient.
     */
    private function usageCounts(): array
    {
        return [
            'recipes as recipes_count' => fn ($q) => $q->select(DB::raw('count(distinct men_item_id)')),
            'addonRecipes',
        ];
    }

    /**
     * Active ingredients only, unless $includeRetired (the Ingredients page).
     * Each row carries recipes_count = number of menu items using it, and
     * addon_recipes_count = number of add-ons using it.
     */
    public function listForCafe(int $cafeId, ?string $search = null, bool $includeRetired = false): Collection
    {
        return Ingredient::where('cafe_id', $cafeId)
            ->when(! $includeRetired, fn ($q) => $q->where('is_active', true))
            ->when($search, fn ($q) => $q->where('normalized_name', 'like', '%' . Ingredient::normalize($search) . '%'))
            ->withCount($this->usageCounts())
            // Names for the card's "Americano • Caramel Macchiato" line.
            ->with(['recipes.item:men_item_id,menu_name', 'addonRecipes.addon:addon_id,name'])
            ->orderBy('name')
            ->get();
    }

    public function findByUuidForCafe(string $uuid, int $cafeId): ?Ingredient
    {
        return Ingredient::where('cafe_id', $cafeId)->where('uuid', $uuid)->withCount($this->usageCounts())->first();
    }

    /** Another ingredient in the cafe with the same name, ignoring case/spacing. */
    public function findNameClash(int $cafeId, string $name, ?int $exceptId = null): ?Ingredient
    {
        return Ingredient::where('cafe_id', $cafeId)
            ->where('normalized_name', Ingredient::normalize($name))
            ->when($exceptId, fn ($q) => $q->where('ingredient_id', '!=', $exceptId))
            ->first();
    }

    public function create(int $cafeId, array $data): Ingredient
    {
        return Ingredient::create([...$data, 'cafe_id' => $cafeId])->loadCount($this->usageCounts());
    }

    /**
     * Recipe rows mirror the ingredient's name, so a rename is copied to them.
     * Consumption logs are left alone — they keep the name used at the time.
     */
    public function update(Ingredient $ingredient, array $data): Ingredient
    {
        $ingredient->update($data);

        if ($ingredient->wasChanged('name')) {
            MenuRecipe::where('ingredient_id', $ingredient->ingredient_id)
                ->update(['ingredient_name' => $ingredient->name]);
            MenuAddonRecipe::where('ingredient_id', $ingredient->ingredient_id)
                ->update(['ingredient_name' => $ingredient->name]);
        }

        return $ingredient->loadCount($this->usageCounts());
    }

    /** @param array<int, string> $uuids */
    public function findByUuids(int $cafeId, array $uuids): Collection
    {
        if (empty($uuids)) {
            return new Collection();
        }

        return Ingredient::where('cafe_id', $cafeId)->whereIn('uuid', $uuids)->get();
    }

    /** @param array<int, string> $normalizedNames */
    public function findByNormalizedNames(int $cafeId, array $normalizedNames): Collection
    {
        if (empty($normalizedNames)) {
            return new Collection();
        }

        return Ingredient::where('cafe_id', $cafeId)->whereIn('normalized_name', $normalizedNames)->get();
    }

    /**
     * Existing ingredient by name (reactivated if it was retired), or a new one.
     */
    public function findOrCreate(int $cafeId, string $name, string $unit): Ingredient
    {
        $ingredient = Ingredient::firstOrCreate(
            ['cafe_id' => $cafeId, 'normalized_name' => Ingredient::normalize($name)],
            ['name' => $name, 'unit' => $unit],
        );

        if (! $ingredient->is_active) {
            $ingredient->update(['is_active' => true]);
        }

        return $ingredient;
    }
}
