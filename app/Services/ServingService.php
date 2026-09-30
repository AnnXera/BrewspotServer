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
