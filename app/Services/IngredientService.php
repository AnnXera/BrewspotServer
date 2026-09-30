<?php

namespace App\Services;

use App\Http\Resources\IngredientResource;
use App\Models\User;
use App\Repository\IngredientRepository;
use App\Repository\MenuItemRepository;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class IngredientService
{
    public function __construct(
        private readonly IngredientRepository $repo,
        private readonly MenuItemRepository $menuRepo
    ) {}

    public function listIngredients(User $owner, ?string $search = null, bool $includeRetired = false): array
    {
        $cafe = $this->menuRepo->findCafeByOwner($owner->user_id);

        if (! $cafe) {
            return ['success' => false, 'message' => 'No cafe found for this account.'];
        }

        return [
            'success'     => true,
            'ingredients' => IngredientResource::collection($this->repo->listForCafe($cafe->cafe_id, $search, $includeRetired)),
        ];
    }

    public function createIngredient(User $owner, array $data): array
    {
        $cafe = $this->menuRepo->findCafeByOwner($owner->user_id);

        if (! $cafe) {
            return ['success' => false, 'http' => 404, 'message' => 'No cafe found for this account.'];
        }

        if ($clash = $this->repo->findNameClash($cafe->cafe_id, $data['name'])) {
            return $this->nameTaken($clash->name, $clash->is_active);
        }

        $ingredient = $this->repo->create($cafe->cafe_id, $data);

        Log::channel('owner')->info('Ingredient created.', [
            'owner_uuid'      => $owner->uuid,
            'ingredient_uuid' => $ingredient->uuid,
        ]);

        return [
            'success'    => true,
            'message'    => 'Ingredient added.',
            'ingredient' => new IngredientResource($ingredient),
        ];
    }

    /**
     * Rename, change unit, or retire/restore. The unit can only change while
     * no recipe uses the ingredient, since recipe amounts are stored in it.
     */
    public function updateIngredient(User $owner, string $uuid, array $data): array
    {
        $cafe = $this->menuRepo->findCafeByOwner($owner->user_id);

        if (! $cafe) {
            return ['success' => false, 'http' => 404, 'message' => 'No cafe found for this account.'];
        }

        $ingredient = $this->repo->findByUuidForCafe($uuid, $cafe->cafe_id);

        if (! $ingredient) {
            return ['success' => false, 'http' => 404, 'message' => 'Ingredient not found.'];
        }

        if (array_key_exists('is_active', $data)) {
            $data['is_active'] = (bool) $data['is_active']; // may arrive as "0"/"1"
        }

        if (isset($data['name']) && ($clash = $this->repo->findNameClash($cafe->cafe_id, $data['name'], $ingredient->ingredient_id))) {
            return $this->nameTaken($clash->name, $clash->is_active);
        }

        if (isset($data['unit']) && $data['unit'] !== $ingredient->unit && $ingredient->recipes_count > 0) {
            $items = $ingredient->recipes_count === 1 ? '1 menu item' : "{$ingredient->recipes_count} menu items";

            return [
                'success' => false,
                'http'    => 422,
                'message' => 'Validation failed.',
                'errors'  => ['unit' => ["Used in {$items}, whose amounts are in {$ingredient->unit}. Remove it from those recipes before changing the unit."]],
            ];
        }

        $ingredient = DB::transaction(fn () => $this->repo->update($ingredient, $data));

        Log::channel('owner')->info('Ingredient updated.', [
            'owner_uuid'      => $owner->uuid,
            'ingredient_uuid' => $ingredient->uuid,
            'fields'          => array_keys($data),
        ]);

        return [
            'success'    => true,
            'message'    => match (true) {
                ($data['is_active'] ?? null) === false => 'Ingredient retired. It no longer shows when adding recipes.',
                ($data['is_active'] ?? null) === true  => 'Ingredient restored.',
                default                                => 'Ingredient updated.',
            },
            'ingredient' => new IngredientResource($ingredient),
        ];
    }

    private function nameTaken(string $existingName, bool $isActive): array
    {
        return [
            'success' => false,
            'http'    => 422,
            'message' => 'Validation failed.',
            'errors'  => ['name' => [$isActive
                ? "{$existingName} is already in your ingredient list."
                : "{$existingName} is already in your list but retired. Restore it instead."]],
        ];
    }
}
