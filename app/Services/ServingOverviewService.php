<?php

namespace App\Services;

use App\Models\CafeBranch;
use App\Models\InventoryServing;
use App\Models\MenuCategory;
use App\Models\TransactionItem;
use App\Repository\InventoryServingRepository;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;

/**
 * Read-only views behind the Servings Management screen (manager + owner):
 * servings per category, ingredients used and the sales log, all for today
 * in the app timezone.
 */
class ServingOverviewService
{
    public function __construct(
        private readonly InventoryServingRepository $repo
    ) {}

    /**
     * Today's servings rolled up per menu category. A category is sold out
     * once nothing is left to sell (every item flagged sold out or at 0).
     */
    public function categories(CafeBranch $branch, ?string $search, string $sort, int $page, int $perPage): array
    {
        $servings = $this->repo->listForDate($branch->branch_id, $this->today());
        $byCategory = $servings->groupBy(fn (InventoryServing $s) => $s->menuItem?->category?->uuid ?? 'uncategorized');

        // Every category visible at the branch gets a card, even with nothing planned today.
        $rows = $this->repo->listAvailableCategoriesForBranch($branch)
            ->map(fn (MenuCategory $c) => $this->categoryRow($c, $byCategory->get($c->uuid, collect())));

        if ($byCategory->has('uncategorized')) {
            $rows->push($this->categoryRow(null, $byCategory->get('uncategorized')));
        }

        if ($search !== null && $search !== '') {
            $rows = $rows->filter(fn (array $r) => stripos($r['name'], $search) !== false);
        }

        $rows = match ($sort) {
            'remaining_desc' => $rows->sortByDesc('remaining'),
            'remaining_asc'  => $rows->sortBy('remaining'),
            default          => $rows->sortBy(fn (array $r) => strtolower($r['name'])),
        };

        return [
            'success'    => true,
            'date'       => $this->today()->toDateString(),
            'categories' => $this->paginate($rows->values(), $page, $perPage),
        ];
    }

    /**
     * One category's items with today's daily limit, stock and status. `uncategorized`
     * stands for items without a category. Items switched off for this branch are
     * listed too (status `branch_unavailable`) but can't be edited.
     *
     * Items visible at the branch start out `available` (eye on, limit 0 = not set);
     * the manager decides to switch one off (`unavailable`).
     *
     * status: available | sold_out (limit used up) | unavailable (switched off today)
     * | branch_unavailable (hidden for this branch by the owner)
     */
    public function categoryItems(CafeBranch $branch, string $categoryUuid, ?string $search, string $sort, int $page, int $perPage): array
    {
        $category = null;

        if ($categoryUuid !== 'uncategorized') {
            $category = $this->repo->findCategoryForCafe($categoryUuid, $branch->cafe_id);

            if (! $category) {
                return ['success' => false, 'http' => 404, 'message' => 'Category not found.'];
            }
        }

        $servings     = $this->repo->listForDate($branch->branch_id, $this->today())->keyBy('men_item_id');
        $availableIds = $this->repo->listAvailableItemsForBranch($branch)->pluck('men_item_id')->flip();

        $rows = $this->repo->listItemsForCategory($branch->cafe_id, $category?->men_category_id)
            ->map(function ($item) use ($servings, $availableIds) {
                /** @var InventoryServing|null $serving */
                $serving  = $servings->get($item->men_item_id);
                $onBranch = $availableIds->has($item->men_item_id);

                $status = match (true) {
                    ! $onBranch                         => 'branch_unavailable',
                    $serving?->is_sold_out              => 'unavailable', // the manager switched it off
                    ! $serving                          => 'available',   // visible and not yet decided
                    $this->remaining($serving) === 0    => 'sold_out',
                    default                             => 'available',
                };

                return [
                    'uuid'            => $item->uuid,
                    'menu_name'       => $item->menu_name,
                    'picture'         => $item->picture ? Storage::disk('public')->url($item->picture) : null,
                    'status'          => $status,
                    'daily_limit'     => $serving?->expected_servings ?? 0,
                    'servings_sold'   => $serving?->servings_sold ?? 0,
                    'available_stock' => $serving ? $this->remaining($serving) : 0,
                    // The eye: on for every item visible at the branch until the manager switches it off.
                    'enabled'         => $onBranch && ! $serving?->is_sold_out,
                    'editable'        => $onBranch,
                ];
            });

        if ($search !== null && $search !== '') {
            $rows = $rows->filter(fn (array $r) => stripos($r['menu_name'], $search) !== false);
        }

        $rows = match ($sort) {
            'stock_desc' => $rows->sortByDesc('available_stock'),
            'stock_asc'  => $rows->sortBy('available_stock'),
            default      => $rows->sortBy(fn (array $r) => strtolower($r['menu_name'])),
        };

        return [
            'success'  => true,
            'date'     => $this->today()->toDateString(),
            'category' => ['uuid' => $category?->uuid, 'name' => $category?->name ?? 'Uncategorized'],
            'items'    => $this->paginate($rows->values(), $page, $perPage),
        ];
    }

    /**
     * Ingredients consumed by today's sales, with each one's share of the
     * most-used ingredient (`percent`) for the progress bars.
     */
    public function ingredientsUsed(CafeBranch $branch, ?string $search, string $sort): array
    {
        $usage = $this->repo->ingredientUsageForDate($branch->branch_id, $this->today())
            ->map(fn ($row) => [
                'name'     => $row->name,
                'unit'     => $row->unit,
                'quantity' => round((float) $row->quantity, 2),
            ]);

        $max = (float) $usage->max('quantity');

        $rows = $usage->map(fn (array $r) => $r + [
            'percent' => $max > 0 ? (int) round($r['quantity'] / $max * 100) : 0,
        ]);

        if ($search !== null && $search !== '') {
            $rows = $rows->filter(fn (array $r) => stripos($r['name'], $search) !== false);
        }

        $rows = match ($sort) {
            'quantity_desc' => $rows->sortByDesc('quantity'),
            'quantity_asc'  => $rows->sortBy('quantity'),
            default         => $rows->sortBy(fn (array $r) => strtolower($r['name'])),
        };

        return [
            'success'     => true,
            'date'        => $this->today()->toDateString(),
            'ingredients' => $rows->values(),
        ];
    }

    /**
     * Items sold at the branch today, newest first.
     */
    public function log(CafeBranch $branch, int $perPage): array
    {
        $lines = $this->repo->salesLinesForDate($branch->branch_id, $this->today(), $perPage);

        $lines->getCollection()->transform(function (TransactionItem $line) {
            $item = $line->menuItem;

            return [
                'uuid'         => $line->uuid,
                // No order number is stored; derived from the transaction id.
                'order_number' => sprintf('ODR-%s-%04d', $line->transaction->created_at->format('Y'), $line->transaction_id),
                'menu_name'    => $item?->menu_name,
                'picture'      => $item?->picture ? Storage::disk('public')->url($item->picture) : null,
                'quantity'     => $line->quantity,
                'sold_at'      => $line->transaction->created_at->toISOString(),
            ];
        });

        return [
            'success' => true,
            'date'    => $this->today()->toDateString(),
            'log'     => $lines->toArray(),
        ];
    }

    private function categoryRow(?MenuCategory $category, Collection $servings): array
    {
        $remaining = $servings->sum(fn (InventoryServing $s) => $s->is_sold_out ? 0 : $this->remaining($s));

        return [
            'uuid'              => $category?->uuid,
            'name'              => $category?->name ?? 'Uncategorized',
            'picture'           => $category?->picture ? Storage::disk('public')->url($category->picture) : null,
            'items'             => $servings->count(),
            'expected_servings' => $servings->sum('expected_servings'),
            'servings_sold'     => $servings->sum('servings_sold'),
            'remaining'         => $remaining,
            'status'            => $remaining > 0 ? 'available' : 'sold_out',
        ];
    }

    private function remaining(InventoryServing $s): int
    {
        return max(0, $s->expected_servings - $s->servings_sold - $s->spoilage_qty);
    }

    private function paginate(Collection $rows, int $page, int $perPage): array
    {
        return (new LengthAwarePaginator(
            $rows->forPage($page, $perPage)->values(),
            $rows->count(),
            $perPage,
            $page
        ))->toArray();
    }

    private function today(): Carbon
    {
        return Carbon::today();
    }
}
