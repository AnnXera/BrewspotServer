<?php

namespace App\Repository;

use App\Models\Cafe;
use App\Models\MenuCategory;

class MenuCategoryRepository
{
    public function findCafeByOwner(int $userId): ?Cafe
    {
        return Cafe::where('user_id', $userId)->first();
    }

    public function create(int $cafeId, string $name): MenuCategory
    {
        return MenuCategory::create([
            'cafe_id' => $cafeId,
            'name'    => $name,
        ]);
    }
}