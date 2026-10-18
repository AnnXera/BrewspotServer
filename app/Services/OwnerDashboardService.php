<?php

namespace App\Services;

use App\Models\User;
use App\Repository\OwnerDashboardRepository;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Owner dashboard: lifetime totals plus an income series for one range
 * (daily / weekly / monthly), all branches combined. Trending items, top
 * categories and the branch breakdown cover the same window as the series.
 *
 * Daily is the current week, Sunday to Saturday; weekly buckets run Sunday to
 * Saturday too. Income is compared with the same stretch of the window before
 * (week to date vs. last week to the same day), so a half-done week is not
 * measured against a full one.
 */
class OwnerDashboardService
{
    /** How many buckets each range shows. */
    private const BUCKETS = [
        'daily'   => 7, // one Sunday-to-Saturday week
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
        [$prevFrom]          = $this->window($range, $this->shift($range, $now, -self::BUCKETS[$range]));
        $prevTo              = $this->shift($range, $now, -self::BUCKETS[$range])->endOfDay();

        $series       = $this->series($range, $this->repo->dailyTotals($branchIds, $from, $to), $from);
        $previousDays = $this->repo->dailyTotals($branchIds, $prevFrom, $prevTo);

        $revenue     = $series->sum('revenue');
        $orders      = $series->sum('orders');
        $prevRevenue = (float) $previousDays->sum('revenue');
        $prevOrders  = (int) $previousDays->sum('orders');

        $items = $this->repo->itemSales($branchIds, $from, $to);

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
            'trending_items'  => $this->trending($items, $orders),
            'top_categories'  => $this->categories($this->repo->categoryOrders($branchIds, $from, $to), $orders),
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
            'daily', 'weekly' => $end->copy()->endOfWeek(Carbon::SATURDAY),
            'monthly'         => $end->copy()->endOfMonth(),
        };

        $first = match ($range) {
            'daily'   => $end->copy()->startOfWeek(Carbon::SUNDAY),
            'weekly'  => $this->shift($range, $end, -(self::BUCKETS[$range] - 1))->startOfWeek(Carbon::SUNDAY),
            'monthly' => $this->shift($range, $end, -(self::BUCKETS[$range] - 1))->startOfMonth(),
        };

        return [$first, $last];
    }

    /**
     * Moves $steps buckets; for daily that is days within the series, and a
     * shift by BUCKETS (7) lands on the same weekday last week.
     */
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
        $today   = Carbon::today();

        for ($i = 0; $i < self::BUCKETS[$range]; $i++) {
            $start = $this->shift($range, $from, $i);
            $end   = match ($range) {
                'daily'   => $start->copy()->endOfDay(),
                'weekly'  => $start->copy()->endOfWeek(Carbon::SATURDAY),
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
                    'daily'   => $start->format('D'),
                    'weekly'  => $start->format('M j'),
                    'monthly' => $start->format('M Y'),
                },
                'revenue' => round($revenue, 2),
                'orders'  => $orders,
                // Later this week: nothing to sell yet, drawn as an empty slot.
                'upcoming' => $start->gt($today),
            ]);
        }

        return $buckets;
    }

    /**
     * Top sellers ranked by how many orders included the item. `share` is the percent
     * of all orders in the window.
     */
    private function trending(Collection $items, int $totalOrders): array
    {
        return $items
            ->sortByDesc(fn ($row) => [(int) $row->orders, (float) $row->sales])
            ->take(5)
            ->values()
            ->map(fn ($row, $i) => [
                'rank'     => $i + 1,
                'uuid'     => $row->uuid,
                'name'     => $row->menu_name,
                'category' => $row->category_name ?? 'Uncategorized',
                'orders'   => (int) $row->orders,
                'quantity' => (int) $row->quantity,
                'sales'    => round((float) $row->sales, 2),
                'share'    => $totalOrders > 0 ? round((int) $row->orders / $totalOrders * 100, 1) : 0,
            ])
            ->all();
    }

    /**
     * Ranked by how many orders included the category. `share` is the percent of all
     * orders in the window, so shares can add up past 100 (one order, several categories).
     */
    private function categories(Collection $rows, int $totalOrders): array
    {
        return $rows
            ->map(fn ($row) => [
                'uuid'     => $row->category_uuid,
                'name'     => $row->category_name ?? 'Uncategorized',
                'orders'   => (int) $row->orders,
                'quantity' => (int) $row->quantity,
                'sales'    => round((float) $row->sales, 2),
                'items'    => (int) $row->items,
                'share'    => $totalOrders > 0 ? round((int) $row->orders / $totalOrders * 100, 1) : 0,
            ])
            ->sortByDesc(fn (array $c) => [$c['orders'], $c['sales']])
            ->take(5)
            ->values()
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
