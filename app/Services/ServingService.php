<?php

namespace App\Services;

use App\Http\Resources\InventoryServingResource;
use App\Models\CafeBranch;
use App\Models\InventoryServing;
use App\Models\MenuItem;
use App\Models\User;
use App\Repository\InventoryServingRepository;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * Daily servings of one branch (the "today's menu" list), shared by the
 * owner and manager dashboards. Only the current day (app timezone) can be
 * read or changed. `servings_sold` belongs to the POS and is never set here.
 *
 * Results carry an `http` key that the controller turns into the status code.
 */
class ServingService
{
    public function __construct(
        private readonly InventoryServingRepository $repo
    ) {}

    public function listToday(CafeBranch $branch): array
    {
        $servings = $this->repo->listForDate($branch->branch_id, $this->today());

        return [
            'success'  => true,
            'date'     => $this->today()->toDateString(),
            'summary'  => [
                'items'             => $servings->count(),
                'expected_servings' => $servings->sum('expected_servings'),
                'servings_sold'     => $servings->sum('servings_sold'),
                'spoilage_qty'      => $servings->sum('spoilage_qty'),
                'sold_out'          => $servings->filter(fn (InventoryServing $s) => $s->is_sold_out)->count(),
            ],
            'servings' => InventoryServingResource::collection($servings),
        ];
    }

    /**
     * Menu items the branch can still add to today's list.
     */
    public function availableItems(CafeBranch $branch): array
    {
        $taken = $this->repo->listForDate($branch->branch_id, $this->today())->pluck('men_item_id')->all();

        $items = $this->repo->listAvailableItemsForBranch($branch)
            ->reject(fn (MenuItem $item) => in_array($item->men_item_id, $taken, true))
            ->map(fn (MenuItem $item) => [
                'uuid'          => $item->uuid,
                'menu_name'     => $item->menu_name,
                'category_uuid' => $item->category?->uuid,
                'category_name' => $item->category?->name,
                'base_price'    => $item->base_price,
                'picture'       => $item->picture ? Storage::disk('public')->url($item->picture) : null,
            ])
            ->values();

        return ['success' => true, 'items' => $items];
    }

    public function create(User $actor, CafeBranch $branch, string $itemUuid, int $expectedServings): array
    {
        $item = $this->repo->findMenuItemForCafe($itemUuid, $branch->cafe_id);

        if (! $item) {
            return ['success' => false, 'http' => 404, 'message' => 'Menu item not found.'];
        }

        $available = $this->repo->listAvailableItemsForBranch($branch)
            ->contains(fn (MenuItem $i) => $i->men_item_id === $item->men_item_id);

        if (! $available) {
            return ['success' => false, 'http' => 422, 'message' => 'This menu item is not available at this branch.'];
        }

        if ($this->repo->existsForDate($item->men_item_id, $branch->branch_id, $this->today())) {
            return $this->duplicate();
        }

        try {
            $serving = $this->repo->create($item->men_item_id, $branch->branch_id, $this->today(), $expectedServings);
        } catch (UniqueConstraintViolationException) {
            return $this->duplicate(); // concurrent add of the same item
        }

        Log::channel('owner')->info('Serving added for today.', [
            'actor_uuid'        => $actor->uuid,
            'branch_uuid'       => $branch->uuid,
            'item_uuid'         => $item->uuid,
            'expected_servings' => $expectedServings,
        ]);

        return [
            'success' => true,
            'message' => 'Menu item added to today\'s servings.',
            'serving' => new InventoryServingResource($serving),
        ];
    }

    /**
     * @param  array{expected_servings?: int, spoilage_qty?: int, is_sold_out?: bool}  $payload
     */
    public function update(User $actor, CafeBranch $branch, string $servingUuid, array $payload): array
    {
        $serving = $this->repo->findForDate($servingUuid, $branch->branch_id, $this->today());

        if (! $serving) {
            return $this->notFound();
        }

        $expected = $payload['expected_servings'] ?? $serving->expected_servings;
        $spoilage = $payload['spoilage_qty'] ?? $serving->spoilage_qty;

        if ($serving->servings_sold + $spoilage > $expected) {
            return [
                'success' => false,
                'http'    => 422,
                'message' => "Expected servings can't be lower than servings sold ({$serving->servings_sold}) plus spoilage ({$spoilage}).",
            ];
        }

        $serving = $this->repo->update($serving, $payload);

        Log::channel('owner')->info('Serving updated.', [
            'actor_uuid'   => $actor->uuid,
            'branch_uuid'  => $branch->uuid,
            'serving_uuid' => $serving->uuid,
            'changes'      => $payload,
        ]);

        return [
            'success' => true,
            'message' => 'Serving updated successfully.',
            'serving' => new InventoryServingResource($serving),
        ];
    }

    /**
     * Save Changes on a category's page: each row sets an item's daily limit and
     * whether it is switched on. All rows apply together or none do.
     *
     * Visible items start switched on. The manager decides to switch one off:
     *  - on with no limit (0): back to the default, no serving row
     *  - limit > 0: serving created or updated
     *  - switched off: kept as a sold-out serving, even at limit 0, so the decision is saved
     *  - a limit below servings sold plus spoilage (so 0 once there are sales) is rejected
     *
     * @param  array<int, array{menu_item_uuid: string, daily_limit: int, enabled: bool}>  $rows
     */
    public function saveCategoryItems(User $actor, CafeBranch $branch, string $categoryUuid, array $rows): array
    {
        $category = null;

        if ($categoryUuid !== 'uncategorized') {
            $category = $this->repo->findCategoryForCafe($categoryUuid, $branch->cafe_id);

            if (! $category) {
                return ['success' => false, 'http' => 404, 'message' => 'Category not found.'];
            }
        }

        $items        = $this->repo->listItemsForCategory($branch->cafe_id, $category?->men_category_id)->keyBy('uuid');
        $availableIds = $this->repo->listAvailableItemsForBranch($branch)->pluck('men_item_id')->flip();
        $servings     = $this->repo->listForDate($branch->branch_id, $this->today())->keyBy('men_item_id');

        $errors = [];
        $plan   = [];
        $seen   = [];

        foreach (array_values($rows) as $i => $row) {
            $item = $items->get($row['menu_item_uuid']);

            if (! $item) {
                $errors["items.$i.menu_item_uuid"][] = 'This item is not in this category.';
                continue;
            }

            if (isset($seen[$item->uuid])) {
                $errors["items.$i.menu_item_uuid"][] = "{$item->menu_name} is listed twice.";
                continue;
            }
            $seen[$item->uuid] = true;

            if (! $availableIds->has($item->men_item_id)) {
                $errors["items.$i.menu_item_uuid"][] = "{$item->menu_name} is not available at this branch.";
                continue;
            }

            $serving = $servings->get($item->men_item_id);
            $limit   = (int) $row['daily_limit'];

            if ($serving && $serving->servings_sold + $serving->spoilage_qty > $limit) {
                $errors["items.$i.daily_limit"][] = $limit === 0
                    ? "{$item->menu_name} already has sales today, so its limit can't be 0."
                    : "{$item->menu_name}: the limit can't be lower than servings sold ({$serving->servings_sold}) plus spoilage ({$serving->spoilage_qty}).";
                continue;
            }

            $plan[] = ['item' => $item, 'serving' => $serving, 'limit' => $limit, 'enabled' => (bool) $row['enabled']];
        }

        if ($errors) {
            return ['success' => false, 'http' => 422, 'message' => 'Some changes could not be saved.', 'errors' => $errors];
        }

        try {
            DB::transaction(function () use ($plan, $branch) {
                foreach ($plan as ['item' => $item, 'serving' => $serving, 'limit' => $limit, 'enabled' => $enabled]) {
                    // Visible items are on by default, so on with no limit is the same as no row.
                    if ($limit === 0 && $enabled) {
                        if ($serving) {
                            $this->repo->delete($serving);
                        }
                        continue;
                    }

                    if ($serving) {
                        $this->repo->update($serving, ['expected_servings' => $limit, 'is_sold_out' => ! $enabled]);
                        continue;
                    }

                    $created = $this->repo->create($item->men_item_id, $branch->branch_id, $this->today(), $limit);

                    if (! $enabled) {
                        $this->repo->update($created, ['is_sold_out' => true]);
                    }
                }
            });
        } catch (UniqueConstraintViolationException) {
            return ['success' => false, 'http' => 422, 'message' => 'Servings changed while saving. Reload and try again.'];
        }

        Log::channel('owner')->info('Category servings saved.', [
            'actor_uuid'    => $actor->uuid,
            'branch_uuid'   => $branch->uuid,
            'category_uuid' => $category?->uuid,
            'rows'          => count($plan),
        ]);

        return ['success' => true, 'message' => 'Servings saved.'];
    }

    public function delete(User $actor, CafeBranch $branch, string $servingUuid): array
    {
        $serving = $this->repo->findForDate($servingUuid, $branch->branch_id, $this->today());

        if (! $serving) {
            return $this->notFound();
        }

        if ($serving->servings_sold > 0) {
            return [
                'success' => false,
                'http'    => 422,
                'message' => 'This item already has sales today and can\'t be removed. Mark it as sold out instead.',
            ];
        }

        $this->repo->delete($serving);

        Log::channel('owner')->info('Serving removed from today.', [
            'actor_uuid'   => $actor->uuid,
            'branch_uuid'  => $branch->uuid,
            'serving_uuid' => $serving->uuid,
        ]);

        return ['success' => true, 'message' => 'Menu item removed from today\'s servings.'];
    }

    private function today(): Carbon
    {
        return Carbon::today();
    }

    private function notFound(): array
    {
        return ['success' => false, 'http' => 404, 'message' => 'Serving not found for today.'];
    }

    private function duplicate(): array
    {
        return ['success' => false, 'http' => 422, 'message' => 'This menu item is already in today\'s servings.'];
    }
}
