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
