<?php

namespace App\Services;

use App\Models\Ingredient;
use App\Repository\IngredientRepository;

/**
 * Turns recipe rows from a menu item or add-on form into rows ready to save.
 * Shared by MenuItemService and MenuAddonService.
 */
class RecipeResolver
{
    public function __construct(
        private readonly IngredientRepository $ingredients
    ) {}

    /**
     * Matches each row to one of the cafe's ingredients (by uuid, or by name
     * ignoring case/spacing). Unmatched names become new ingredients when the
     * recipe is saved. Rejects a unit that differs from the ingredient's, and
     * the same ingredient twice where it would be counted twice: twice for one
     * size, or for one size and again for all sizes.
     *
     * size_index points into the item's sizes (0-based); null means every size.
     * With no sizes ($sizeCount = 0) it is ignored.
     *
     * @return array Resolved rows ({ingredient, name, quantity, unit, size_index}), or a 422 result with 'errors'.
     */
    public function resolve(int $cafeId, array $recipes, int $sizeCount = 0): array
    {
        $byUuid = $this->ingredients->findByUuids(
            $cafeId, array_values(array_filter(array_column($recipes, 'ingredient_uuid')))
        )->keyBy('uuid');

        $byName = $this->ingredients->findByNormalizedNames(
            $cafeId, array_map(fn ($n) => Ingredient::normalize($n), array_filter(array_column($recipes, 'ingredient_name')))
        )->keyBy('normalized_name');

        $resolved = [];
        $seen     = []; // ingredient key => [scope => true], scope 'all' or a size index
        $errors   = [];

        foreach (array_values($recipes) as $i => $row) {
            $sizeIndex = $sizeCount > 0 && isset($row['size_index']) ? (int) $row['size_index'] : null;

            if ($sizeIndex !== null && $sizeIndex >= $sizeCount) {
                $errors["recipes.$i.size_index"][] = 'That size no longer exists. Pick it again.';
                continue;
            }

            if (! empty($row['ingredient_uuid'])) {
                $ingredient = $byUuid->get($row['ingredient_uuid']);

                if (! $ingredient) {
                    $errors["recipes.$i.ingredient_uuid"][] = 'That ingredient no longer exists. Pick it again.';
                    continue;
                }
            } else {
                $ingredient = $byName->get(Ingredient::normalize($row['ingredient_name']));
            }

            $name  = $ingredient?->name ?? Ingredient::tidy($row['ingredient_name']);
            $key   = $ingredient ? "id:{$ingredient->ingredient_id}" : 'new:' . Ingredient::normalize($name);
            $scope = $sizeIndex ?? 'all';

            if (isset($seen[$key][$scope])) {
                $errors["recipes.$i.ingredient_name"][] = "{$name} is already in this recipe.";
                continue;
            }

            if ($scope === 'all' ? isset($seen[$key]) : isset($seen[$key]['all'])) {
                $errors["recipes.$i.ingredient_name"][] = "{$name} is already used for all sizes. Set its amount per size or for all sizes, not both.";
                continue;
            }
            $seen[$key][$scope] = true;

            if ($ingredient && $ingredient->unit !== $row['unit']) {
                $errors["recipes.$i.unit"][] = "{$ingredient->name} is measured in {$ingredient->unit}.";
                continue;
            }

            $resolved[] = [
                'ingredient' => $ingredient,
                'name'       => $name,
                'quantity'   => $row['quantity'],
                'unit'       => $row['unit'],
                'size_index' => $sizeIndex,
            ];
        }

        if ($errors) {
            return ['success' => false, 'http' => 422, 'message' => 'Validation failed.', 'errors' => $errors];
        }

        return $resolved;
    }
}
