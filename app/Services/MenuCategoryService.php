<?php

namespace App\Services;

use App\Http\Resources\MenuCategoryResource;
use App\Models\ActivityLog;
use App\Models\User;
use App\Repository\MenuCategoryRepository;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Http\UploadedFile;

class MenuCategoryService
{
    public function __construct(
        private readonly MenuCategoryRepository $repo,
        private readonly ActivityLogService $activity
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
                $this->repo->assignItems($cafe->cafe_id, $category->men_category_id, $payload['items']);
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

        $this->activity->menu($owner, $cafe->cafe_id, null, ActivityLog::MENU_CATEGORY_CREATED,
            "Added menu category \"{$category->name}\"",
            ['category' => $category->name, 'items' => count($payload['items'] ?? [])]
        );

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

        $before = ['name' => $category->name, 'is_available' => (bool) $category->is_available];

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
                $this->repo->detachItems($category->men_category_id);

                if (count($payload['items']) > 0) {
                    $this->repo->assignItems($cafe->cafe_id, $category->men_category_id, $payload['items']);
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

        $parts = [];
        if ($category->name !== $before['name']) {
            $parts[] = "renamed from \"{$before['name']}\"";
        }
        if ((bool) $category->is_available !== $before['is_available']) {
            $parts[] = $category->is_available ? 'switched on' : 'switched off';
        }
        if (isset($payload['items']) && is_array($payload['items'])) {
            $parts[] = 'items set to ' . count($payload['items']);
        }

        $this->activity->menu($owner, $cafe->cafe_id, null, ActivityLog::MENU_CATEGORY_UPDATED,
            "Edited menu category \"{$category->name}\"" . ($parts ? ': ' . implode(', ', $parts) : ''),
            ['category' => $category->name, 'before' => $before, 'after' => ['name' => $category->name, 'is_available' => (bool) $category->is_available]]
        );

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

        $this->repo->detachItems($category->men_category_id);
        $this->repo->delete($category);

        Log::channel('owner')->info('Menu category deleted.', [
            'owner_uuid'    => $owner->uuid,
            'category_uuid' => $uuid,
        ]);

        $this->activity->menu($owner, $cafe->cafe_id, null, ActivityLog::MENU_CATEGORY_DELETED,
            "Deleted menu category \"{$category->name}\" (its items are now uncategorized)",
            ['category' => $category->name]
        );

        return [
            'success' => true,
            'message' => 'Menu category deleted successfully.',
        ];
    }
}