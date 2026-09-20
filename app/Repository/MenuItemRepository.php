<?php

namespace App\Repository;

use App\Models\Cafe;
use App\Models\MenuCategory;
use App\Models\MenuItem;
use Illuminate\Support\Facades\DB;

class MenuItemRepository
{
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

    public function create(int $categoryId, array $payload, array $recipes): MenuItem
    {
        return DB::transaction(function () use ($categoryId, $payload, $recipes) {
            $item = MenuItem::create([
                'men_category_id' => $categoryId,
                'menu_name'       => $payload['menu_name'],
                'description'     => $payload['description'] ?? null,
                'base_price'      => $payload['base_price'],
                'is_available'    => true, // automatically true when created
                'picture'         => $payload['picture'] ?? null,
            ]);

            foreach ($recipes as $recipe) {
                $item->recipes()->create([
                    'ingredient_name' => $recipe['ingredient_name'],
                    'quantity'        => $recipe['quantity'],
                    'unit'            => $recipe['unit'],
                ]);
            }

            return $item->load('recipes');
        });
    }

    public function findByUuidForCafe(string $uuid, int $cafeId): ?MenuItem
    {
        return MenuItem::where('uuid', $uuid)
            ->whereHas('category', function ($query) use ($cafeId) {
                $query->where('cafe_id', $cafeId);
            })
            ->with('recipes')
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
                'men_category_id' => $payload['men_category_id'] ?? null,
            ], fn ($value) => $value !== null);

            if (!empty($updateData)) {
                $item->update($updateData);
            }

            if ($recipes !== null) {
                // Delete old recipes and insert new ones
                $item->recipes()->delete();
                foreach ($recipes as $recipe) {
                    $item->recipes()->create([
                        'ingredient_name' => $recipe['ingredient_name'],
                        'quantity'        => $recipe['quantity'],
                        'unit'            => $recipe['unit'],
                    ]);
                }
            }

            return $item->fresh('recipes');
        });
    }

    public function listByCafe(int $cafeId)
    {
        return MenuItem::whereHas('category', function ($query) use ($cafeId) {
                $query->where('cafe_id', $cafeId);
            })
            ->with(['category', 'recipes'])
            ->orderBy('menu_name')
            ->get();
    }

    public function delete(MenuItem $item): void
    {
        $item->delete();
    }
}
