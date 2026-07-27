<?php

namespace App\Services;

use App\Http\Resources\MenuCategoryResource;
use App\Models\User;
use App\Repository\MenuCategoryRepository;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Log;

class MenuCategoryService
{
    public function __construct(
        private readonly MenuCategoryRepository $repo
    ) {}

    public function createCategory(User $owner, string $name): array
    {
        $cafe = $this->repo->findCafeByOwner($owner->user_id);

        if (! $cafe) {
            Log::channel('owner')->warning('Menu category creation blocked — owner has no cafe.', [
                'owner_uuid' => $owner->uuid,
            ]);

            return [
                'success' => false,
                'message' => 'No cafe found for this account.',
            ];
        }

        try {
            $category = $this->repo->create($cafe->cafe_id, $name);
        } catch (QueryException $e) {
            if ($e->getCode() === '23000') { // integrity constraint violation (duplicate cafe_id + name)
                Log::channel('owner')->warning('Menu category creation blocked — duplicate name for cafe.', [
                    'owner_uuid' => $owner->uuid,
                    'cafe_uuid'  => $cafe->uuid,
                    'name'       => $name,
                ]);

                return [
                    'success' => false,
                    'message' => 'A category with this name already exists for your cafe.',
                ];
            }

            throw $e;
        }

        Log::channel('owner')->info('Menu category created.', [
            'owner_uuid'    => $owner->uuid,
            'cafe_uuid'     => $cafe->uuid,
            'category_uuid' => $category->uuid,
            'name'          => $category->name,
        ]);

        return [
            'success'  => true,
            'message'  => 'Menu category created successfully.',
            'category' => new MenuCategoryResource($category),
        ];
    }
}