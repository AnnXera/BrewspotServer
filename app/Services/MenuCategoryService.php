<?php

namespace App\Services;

use App\Http\Resources\MenuCategoryResource;
use App\Models\User;
use App\Repository\MenuCategoryRepository;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Http\UploadedFile;

class MenuCategoryService
{
    public function __construct(
        private readonly MenuCategoryRepository $repo
    ) {}

    public function createCategory(User $owner, array $payload): array
    {
        $cafe = $this->repo->findCafeByOwner($owner->user_id);

        if (! $cafe) {
            Log::channel('owner')->warning('Menu category creation blocked — owner has no cafe.', [
                'owner_uuid' => $owner->uuid,
            ]);

            return ['success' => false, 'message' => 'No cafe found for this account.'];
        }

        try {
            if (isset($payload['picture']) && $payload['picture'] instanceof UploadedFile) {
                $path = 'users/' . $owner->uuid . '/cafes/menu-category';
                $payload['picture'] = $payload['picture']->store($path, 'public');
            }

            $category = $this->repo->create($cafe->cafe_id, $payload);

            if (isset($payload['items']) && is_array($payload['items']) && count($payload['items']) > 0) {
                \App\Models\MenuItem::where('cafe_id', $cafe->cafe_id)
                    ->whereIn('uuid', $payload['items'])
                    ->update(['men_category_id' => $category->men_category_id]);
            }
        } catch (QueryException $e) {
            if ($e->getCode() === '23000') {
                Log::channel('owner')->warning('Menu category creation blocked — duplicate name for cafe.', [
                    'owner_uuid' => $owner->uuid,
                    'cafe_uuid'  => $cafe->uuid,
                    'name'       => $payload['name'],
                ]);

                return ['success' => false, 'message' => 'A category with this name already exists for your cafe.'];
            }

            throw $e;
        }

        Log::channel('owner')->info('Menu category created.', [
            'owner_uuid'    => $owner->uuid,
            'cafe_uuid'     => $cafe->uuid,
            'category_uuid' => $category->uuid,
            'name'          => $category->name,
            'is_available'  => $category->is_available,
        ]);

        return [
            'success'  => true,
            'message'  => 'Menu category created successfully.',
            'category' => new MenuCategoryResource($category),
        ];
    }

    public function updateCategory(User $owner, string $uuid, array $payload): array
    {
        $cafe = $this->repo->findCafeByOwner($owner->user_id);

        if (! $cafe) {
            return ['success' => false, 'message' => 'No cafe found for this account.'];
        }

        $category = $this->repo->findByUuidForCafe($uuid, $cafe->cafe_id);

        if (! $category) {
            Log::channel('owner')->warning('Menu category update blocked — not found or not owned.', [
                'owner_uuid'    => $owner->uuid,
                'category_uuid' => $uuid,
            ]);

            return ['success' => false, 'message' => 'Category not found.'];
        }

        try {
            if (isset($payload['picture']) && $payload['picture'] instanceof UploadedFile) {
                if ($category->picture) {
                    Storage::disk('public')->delete($category->picture);
                }
                $path = 'users/' . $owner->uuid . '/cafe/menu-category';
                $payload['picture'] = $payload['picture']->store($path, 'public');
            }

            $category = $this->repo->update($category, $payload);

            if (isset($payload['items']) && is_array($payload['items'])) {
                // Clear existing items for this category
                \App\Models\MenuItem::where('men_category_id', $category->men_category_id)
                    ->update(['men_category_id' => null]);
                
                // Assign new items
                if (count($payload['items']) > 0) {
                    \App\Models\MenuItem::where('cafe_id', $cafe->cafe_id)
                        ->whereIn('uuid', $payload['items'])
                        ->update(['men_category_id' => $category->men_category_id]);
                }
            }
        } catch (QueryException $e) {
            if ($e->getCode() === '23000') {
                return ['success' => false, 'message' => 'A category with this name already exists for your cafe.'];
            }

            throw $e;
        }

        Log::channel('owner')->info('Menu category updated.', [
            'owner_uuid'    => $owner->uuid,
            'category_uuid' => $category->uuid,
            'is_available'  => $category->is_available,
        ]);

        return [
            'success'  => true,
            'message'  => 'Menu category updated successfully.',
            'category' => new MenuCategoryResource($category),
        ];
    }

    public function listCategories(User $owner): array
    {
        $cafe = $this->repo->findCafeByOwner($owner->user_id);

        if (! $cafe) {
            return ['success' => false, 'message' => 'No cafe found for this account.'];
        }

        $categories = $this->repo->listByCafe($cafe->cafe_id);

        return [
            'success'    => true,
            'categories' => MenuCategoryResource::collection($categories),
        ];
    }

    public function deleteCategory(User $owner, string $uuid): array
    {
        $cafe = $this->repo->findCafeByOwner($owner->user_id);

        if (! $cafe) {
            return ['success' => false, 'message' => 'No cafe found for this account.'];
        }

        $category = $this->repo->findByUuidForCafe($uuid, $cafe->cafe_id);

        if (! $category) {
            Log::channel('owner')->warning('Menu category deletion blocked — not found or not owned.', [
                'owner_uuid'    => $owner->uuid,
                'category_uuid' => $uuid,
            ]);

            return ['success' => false, 'message' => 'Category not found.'];
        }

        if ($category->picture) {
            Storage::disk('public')->delete($category->picture);
        }

        \App\Models\MenuItem::where('men_category_id', $category->men_category_id)
            ->update(['men_category_id' => null]);

        $this->repo->delete($category);

        Log::channel('owner')->info('Menu category deleted.', [
            'owner_uuid'    => $owner->uuid,
            'category_uuid' => $uuid,
        ]);

        return [
            'success' => true,
            'message' => 'Menu category deleted successfully.',
        ];
    }
}