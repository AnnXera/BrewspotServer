<?php

namespace App\Repository;

use App\Models\Cafe;
use App\Models\MenuCategory;
use App\Models\MenuItem;

class MenuCategoryRepository
{
    public function findCafeByOwner(int $userId): ?Cafe
    {
        return Cafe::where('user_id', $userId)->first();
    }

    public function create(int $cafeId, array $payload): MenuCategory
    {
        return MenuCategory::create([
            'cafe_id'      => $cafeId,
            'name'         => $payload['name'],
            'is_available' => true, // Always default to true on creation
            'picture'      => $payload['picture'] ?? null,
        ]);
    }

    public function findByUuidForCafe(string $uuid, int $cafeId): ?MenuCategory
    {
        return MenuCategory::where('uuid', $uuid)
            ->where('cafe_id', $cafeId)
            ->first();
    }

    public function update(MenuCategory $category, array $payload): MenuCategory
    {
        $category->update(array_filter([
            'name'         => $payload['name'] ?? null,
            'is_available' => array_key_exists('is_available', $payload) ? $payload['is_available'] : null,
            'picture'      => array_key_exists('picture', $payload) ? $payload['picture'] : null,
        ], fn ($value) => $value !== null));

        return $category->fresh();
    }

    public function listByCafe(int $cafeId)
    {
        return MenuCategory::where('cafe_id', $cafeId)
            ->with('items')
            ->orderBy('name')
            ->get();
    }

    /** Move the given cafe items (by uuid) into this category. */
    public function assignItems(int $cafeId, int $categoryId, array $itemUuids): void
    {
        MenuItem::where('cafe_id', $cafeId)
            ->whereIn('uuid', $itemUuids)
            ->update(['men_category_id' => $categoryId]);
    }

    /** Leave every item in this category uncategorised. */
    public function detachItems(int $categoryId): void
    {
        MenuItem::where('men_category_id', $categoryId)
            ->update(['men_category_id' => null]);
    }

    public function delete(MenuCategory $category): void
    {
        $category->delete();
    }
}