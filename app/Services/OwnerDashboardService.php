<?php

namespace App\Services;

use App\Models\User;
use App\Repository\OwnerDashboardRepository;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;

/**
 * Owner dashboard: lifetime totals plus an income series for one range
 * (daily / weekly / monthly), all branches combined. Trending items, top
 * categories and the branch breakdown cover the same window as the series,
 * compared against the window right before it.
 */
class OwnerDashboardService
{
    /** How many buckets each range shows. */
    private const BUCKETS = [
        'daily'   => 14,
        'weekly'  => 12,
        'monthly' => 12,
    ];

    public function __construct(
        private readonly OwnerDashboardRepository $repo
    ) {}

    public function stats(User $owner, string $range): array
    {
        $now       = Carbon::now();
        $branchIds = $this->repo->branchIdsByOwner($owner->user_id);
        $branches  = $this->repo->branchesByOwner($owner->user_id);

        [$from, $to]         = $this->window($range, $now);
        [$prevFrom, $prevTo] = $this->window($range, $this->shift($range, $now, -self::BUCKETS[$range]));

        $series       = $this->series($range, $this->repo->dailyTotals($branchIds, $from, $to), $from);
        $previousDays = $this->repo->dailyTotals($branchIds, $prevFrom, $prevTo);

        $revenue     = $series->sum('revenue');
        $orders      = $series->sum('orders');
        $prevRevenue = (float) $previousDays->sum('revenue');
        $prevOrders  = (int) $previousDays->sum('orders');

        $items     = $this->repo->itemSales($branchIds, $from, $to);
        $prevItems = $this->repo->itemSales($branchIds, $prevFrom, $prevTo)->keyBy('men_item_id');

        $today = $this->repo->dailyTotals($branchIds, $now->copy()->startOfDay(), $now->copy()->endOfDay())->first();

        return [
            'success'      => true,
            'generated_at' => $now->toISOString(),
            'totals'       => [
                ...$this->branchCounts($branches),
                ...$this->repo->lifetimeTotals($branchIds),
                'today_revenue' => (float) ($today->revenue ?? 0),
                'today_orders'  => (int) ($today->orders ?? 0),
            ],
            'period' => [
                'range'            => $range,
                'from'             => $from->toDateString(),
                'to'               => $to->toDateString(),
                'revenue'          => round($revenue, 2),
                'orders'           => $orders,
                'avg_order_value'  => $orders > 0 ? round($revenue / $orders, 2) : 0,
                'previous_revenue' => round($prevRevenue, 2),
                'previous_orders'  => $prevOrders,
                'revenue_change'   => $this->change($revenue, $prevRevenue),
                'orders_change'    => $this->change($orders, $prevOrders),
            ],
            'series'          => $series->values(),
            'trending_items'  => $this->trending($items, $prevItems),
            'top_categories'  => $this->categories($items),
            'branches'        => $this->branchBreakdown($branches, $this->repo->branchSales($branchIds, $from, $to)),
        ];
    }

    private function branchCounts(Collection $branches): array
    {
        $active  = $branches->whereIn('status', ['active', 'approved'])->count();
        $pending = $branches->where('status', 'pending_approval')->count();

        return [
            'branches'         => $branches->count(),
            'active_branches'  => $active,
            'pending_branches' => $pending,
        ];
    }

    /**
     * @return array{0: Carbon, 1: Carbon} the range's buckets ending with the one holding $end
     */
    private function window(string $range, Carbon $end): array
    {
        $last = match ($range) {
            'daily'   => $end->copy()->endOfDay(),
            'weekly'  => $end->copy()->endOfWeek(Carbon::SUNDAY),
            'monthly' => $end->copy()->endOfMonth(),
        };

        $first = $this->shift($range, $end, -(self::BUCKETS[$range] - 1));
        $first = match ($range) {
            'daily'   => $first->startOfDay(),
            'weekly'  => $first->startOfWeek(Carbon::MONDAY),
            'monthly' => $first->startOfMonth(),
        };

        return [$first, $last];
    }

    private function shift(string $range, Carbon $date, int $steps): Carbon
    {
        return match ($range) {
            'daily'   => $date->copy()->addDays($steps),
            'weekly'  => $date->copy()->addWeeks($steps),
            'monthly' => $date->copy()->addMonthsNoOverflow($steps),
        };
    }

    /**
     * Rolls the per-day totals into the range's buckets; empty buckets stay at zero.
     */
    private function series(string $range, Collection $days, Carbon $from): Collection
    {
        $buckets = collect();

        for ($i = 0; $i < self::BUCKETS[$range]; $i++) {
            $start = $this->shift($range, $from, $i);
            $end   = match ($range) {
                'daily'   => $start->copy()->endOfDay(),
                'weekly'  => $start->copy()->endOfWeek(Carbon::SUNDAY),
                'monthly' => $start->copy()->endOfMonth(),
            };

            $revenue = 0.0;
            $orders  = 0;
            for ($d = $start->copy(); $d->lte($end); $d->addDay()) {
                $row = $days->get($d->toDateString());
                if ($row) {
                    $revenue += (float) $row->revenue;
                    $orders  += (int) $row->orders;
                }
            }

            $buckets->push([
                'start'   => $start->toDateString(),
                'end'     => $end->toDateString(),
                'label'   => match ($range) {
                    'daily'   => $start->format('M j'),
                    'weekly'  => $start->format('M j'),
                    'monthly' => $start->format('M Y'),
                },
                'revenue' => round($revenue, 2),
                'orders'  => $orders,
            ]);
        }

        return $buckets;
    }

    private function trending(Collection $items, Collection $previous): array
    {
        $totalQty = max(1, (int) $items->sum('quantity'));

        return $items
            ->sortByDesc(fn ($row) => [(int) $row->quantity, (float) $row->sales])
            ->take(5)
            ->values()
            ->map(function ($row, $i) use ($previous, $totalQty) {
                $prevQty = (int) ($previous->get($row->men_item_id)->quantity ?? 0);

                return [
                    'rank'              => $i + 1,
                    'uuid'              => $row->uuid,
                    'name'              => $row->menu_name,
                    'category'          => $row->category_name ?? 'Uncategorized',
                    'picture'           => $row->picture ? Storage::disk('public')->url($row->picture) : null,
                    'quantity'          => (int) $row->quantity,
                    'sales'             => round((float) $row->sales, 2),
                    'share'             => round((int) $row->quantity / $totalQty * 100, 1),
                    'previous_quantity' => $prevQty,
                    'change'            => $this->change((int) $row->quantity, $prevQty),
                ];
            })
            ->all();
    }

    private function categories(Collection $items): array
    {
        $totalSales = (float) $items->sum('sales');

        return $items
            ->groupBy(fn ($row) => $row->category_uuid ?? 'uncategorized')
            ->map(fn (Collection $rows) => [
                'uuid'     => $rows->first()->category_uuid,
                'name'     => $rows->first()->category_name ?? 'Uncategorized',
                'quantity' => (int) $rows->sum('quantity'),
                'sales'    => round((float) $rows->sum('sales'), 2),
                'items'    => $rows->count(),
            ])
            ->sortByDesc('sales')
            ->take(5)
            ->values()
            ->map(fn (array $c) => [...$c, 'share' => $totalSales > 0 ? round($c['sales'] / $totalSales * 100, 1) : 0])
            ->all();
    }

    private function branchBreakdown(Collection $branches, Collection $sales): array
    {
        $total = (float) $sales->sum('revenue');

        return $branches
            ->map(function ($branch) use ($sales, $total) {
                $row     = $sales->get($branch->branch_id);
                $revenue = (float) ($row->revenue ?? 0);

                return [
                    'uuid'        => $branch->uuid,
                    'name'        => $branch->branch_name,
                    'branch_type' => $branch->branch_type,
                    'status'      => $branch->status,
                    'revenue'     => round($revenue, 2),
                    'orders'      => (int) ($row->orders ?? 0),
                    'share'       => $total > 0 ? round($revenue / $total * 100, 1) : 0,
                ];
            })
            ->sortByDesc('revenue')
            ->values()
            ->all();
    }

    /**
     * Percent change against the previous window; null when there is nothing to compare to.
     */
    private function change(float $current, float $previous): ?float
    {
        if ($previous <= 0) {
            return null;
        }

        return round(($current - $previous) / $previous * 100, 1);
    }
}
