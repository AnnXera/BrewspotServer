<?php

namespace App\Services;

use App\Models\User;
use App\Repository\OwnerAnalyticsRepository;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Owner Analytics page. Each section (sales, menu, branches, operations) is its own
 * call so a tab only pays for what it shows.
 *
 * Every call covers [from, to] (whole days, app timezone) for all of the owner's
 * branches, or one branch when a branch uuid is given. Comparisons use the window of
 * the same length right before it.
 */
class OwnerAnalyticsService
{
    public function __construct(
        private readonly OwnerAnalyticsRepository $repo
    ) {}

    // ── Sales ────────────────────────────────────────────────────────────

    public function sales(User $owner, array $filters): array
    {
        $scope = $this->scope($owner, $filters);
        if (isset($scope['error'])) {
            return $scope['error'];
        }

        [$from, $to, $prevFrom, $prevTo, $ids] = [$scope['from'], $scope['to'], $scope['prev_from'], $scope['prev_to'], $scope['branch_ids']];

        $now  = $this->repo->salesTotals($ids, $from, $to);
        $prev = $this->repo->salesTotals($ids, $prevFrom, $prevTo);
        $void = $this->repo->unsettledTotals($ids, $from, $to);

        $revenue  = (float) $now->revenue;
        $orders   = (int) $now->orders;
        $aov      = $orders > 0 ? $revenue / $orders : 0;
        $pRevenue = (float) $prev->revenue;
        $pOrders  = (int) $prev->orders;
        $pAov     = $pOrders > 0 ? $pRevenue / $pOrders : 0;

        $granularity = $this->granularity($from, $to);
        $days        = $this->repo->dailyTotals($ids, $from, $to);

        $payments = $this->repo->paymentMethods($ids, $from, $to);
        $payTotal = max(1, (int) $payments->sum('orders'));

        return [
            'success' => true,
            'filters' => $this->filtersOut($scope),
            'summary' => [
                'revenue'           => round($revenue, 2),
                'orders'            => $orders,
                'avg_order_value'   => round($aov, 2),
                'items_sold'        => $this->repo->itemsSold($ids, $from, $to),
                'discounts'         => round((float) $now->discounts, 2),
                'discounted_orders' => (int) $now->discounted_orders,
                'voided_orders'     => (int) $void->orders,
                'voided_amount'     => round((float) $void->amount, 2),
                'previous'          => [
                    'revenue'         => round($pRevenue, 2),
                    'orders'          => $pOrders,
                    'avg_order_value' => round($pAov, 2),
                ],
                'change' => [
                    'revenue'         => $this->change($revenue, $pRevenue),
                    'orders'          => $this->change($orders, $pOrders),
                    'avg_order_value' => $this->change($aov, $pAov),
                ],
            ],
            'granularity' => $granularity,
            'series'      => $this->series($granularity, $days, $from, $to),
            'heatmap'     => $this->heatmap($this->repo->hourlyTotals($ids, $from, $to)),
            'payments'    => $payments
                ->map(fn ($row) => [
                    'method' => $row->method ?: 'unknown',
                    'orders' => (int) $row->orders,
                    'amount' => round((float) $row->amount, 2),
                    'share'  => round((int) $row->orders / $payTotal * 100, 1),
                ])
                ->sortByDesc('orders')
                ->values()
                ->all(),
            'discounts' => $this->repo->discountTypes($ids, $from, $to)
                ->map(fn ($row) => [
                    'type'   => $row->type ?: 'other',
                    'orders' => (int) $row->orders,
                    'amount' => round((float) $row->amount, 2),
                ])
                ->sortByDesc('amount')
                ->values()
                ->all(),
        ];
    }

    // ── Menu ─────────────────────────────────────────────────────────────

    public function menu(User $owner, array $filters): array
    {
        $scope = $this->scope($owner, $filters);
        if (isset($scope['error'])) {
            return $scope['error'];
        }

        [$from, $to, $prevFrom, $prevTo, $ids] = [$scope['from'], $scope['to'], $scope['prev_from'], $scope['prev_to'], $scope['branch_ids']];

        $items      = $this->repo->itemSales($ids, $from, $to);
        $previous   = $this->repo->itemSales($ids, $prevFrom, $prevTo)->keyBy('men_item_id');
        $totalSales = (float) $items->sum('sales');
        $orders     = (int) $this->repo->salesTotals($ids, $from, $to)->orders;
        $lines      = max(1, $this->repo->itemLineCount($ids, $from, $to));

        $sold = $items->pluck('men_item_id')->flip();

        return [
            'success' => true,
            'filters' => $this->filtersOut($scope),
            'summary' => [
                'items_sold'     => (int) $items->sum('quantity'),
                'distinct_items' => $items->count(),
                'sales'          => round($totalSales, 2),
            ],
            'items' => $items
                ->map(function ($row) use ($previous, $totalSales, $orders) {
                    $prevQty = (int) ($previous->get($row->men_item_id)->quantity ?? 0);

                    return [
                        'uuid'              => $row->uuid,
                        'name'              => $row->menu_name,
                        'category'          => $row->category_name ?? 'Uncategorized',
                        'orders'            => (int) $row->orders,
                        'quantity'          => (int) $row->quantity,
                        'sales'             => round((float) $row->sales, 2),
                        'sales_share'       => $totalSales > 0 ? round((float) $row->sales / $totalSales * 100, 1) : 0,
                        'order_share'       => $orders > 0 ? round((int) $row->orders / $orders * 100, 1) : 0,
                        'previous_quantity' => $prevQty,
                        'change'            => $this->change((int) $row->quantity, $prevQty),
                    ];
                })
                ->sortByDesc('sales')
                ->values()
                ->all(),
            // On the menu but not sold once in the window.
            'not_selling' => $this->repo->liveMenuItems($scope['cafe_ids'])
                ->reject(fn ($item) => $sold->has($item->men_item_id))
                ->map(fn ($item) => [
                    'uuid'     => $item->uuid,
                    'name'     => $item->menu_name,
                    'category' => $item->category_name ?? 'Uncategorized',
                ])
                ->values()
                ->all(),
            'categories' => $this->repo->categoryOrders($ids, $from, $to)
                ->map(fn ($row) => [
                    'uuid'        => $row->category_uuid,
                    'name'        => $row->category_name ?? 'Uncategorized',
                    'orders'      => (int) $row->orders,
                    'quantity'    => (int) $row->quantity,
                    'sales'       => round((float) $row->sales, 2),
                    'order_share' => $orders > 0 ? round((int) $row->orders / $orders * 100, 1) : 0,
                    'sales_share' => $totalSales > 0 ? round((float) $row->sales / $totalSales * 100, 1) : 0,
                ])
                ->sortByDesc(fn (array $c) => [$c['orders'], $c['sales']])
                ->values()
                ->all(),
            'sizes' => $this->withShare(
                $this->repo->sizeSales($ids, $from, $to)->map(fn ($row) => [
                    'name'     => $row->name,
                    'quantity' => (int) $row->quantity,
                    'sales'    => round((float) $row->sales, 2),
                ]),
                'quantity'
            ),
            // attach_rate: percent of item lines (one drink row on a receipt) carrying the add-on.
            'addons' => $this->repo->addonSales($ids, $from, $to)
                ->map(fn ($row) => [
                    'name'        => $row->name,
                    'quantity'    => (int) $row->quantity,
                    'sales'       => round((float) $row->sales, 2),
                    'attach_rate' => round((int) $row->line_count / $lines * 100, 1),
                ])
                ->sortByDesc('quantity')
                ->values()
                ->all(),
        ];
    }

    // ── Branches ─────────────────────────────────────────────────────────

    /**
     * Always every branch, side by side; the branch filter does not apply here.
     */
    public function branches(User $owner, array $filters): array
    {
        $scope = $this->scope($owner, [...$filters, 'branch' => null]);

        [$from, $to, $prevFrom, $prevTo] = [$scope['from'], $scope['to'], $scope['prev_from'], $scope['prev_to']];

        $live  = $scope['branches']->whereNull('deleted_at');
        $now   = $this->repo->branchSales($scope['branch_ids'], $from, $to);
        $prev  = $this->repo->branchSales($scope['branch_ids'], $prevFrom, $prevTo);
        $total = (float) $now->sum('revenue');

        return [
            'success'  => true,
            'filters'  => $this->filtersOut($scope),
            'branches' => $live
                ->map(function ($b) use ($now, $prev, $total) {
                    $row     = $now->get($b->branch_id);
                    $revenue = (float) ($row->revenue ?? 0);
                    $orders  = (int) ($row->orders ?? 0);
                    $pRev    = (float) ($prev->get($b->branch_id)->revenue ?? 0);

                    return [
                        'uuid'             => $b->uuid,
                        'name'             => $b->branch_name,
                        'branch_type'      => $b->branch_type,
                        'status'           => $b->status,
                        'revenue'          => round($revenue, 2),
                        'orders'           => $orders,
                        'avg_order_value'  => $orders > 0 ? round($revenue / $orders, 2) : 0,
                        'share'            => $total > 0 ? round($revenue / $total * 100, 1) : 0,
                        'previous_revenue' => round($pRev, 2),
                        'change'           => $this->change($revenue, $pRev),
                    ];
                })
                ->sortByDesc('revenue')
                ->values()
                ->all(),
        ];
    }

    // ── Operations ───────────────────────────────────────────────────────

    public function operations(User $owner, array $filters): array
    {
        $scope = $this->scope($owner, $filters);
        if (isset($scope['error'])) {
            return $scope['error'];
        }

        [$from, $to, $ids] = [$scope['from'], $scope['to'], $scope['branch_ids']];

        $servings     = $this->repo->servings($ids, $from, $to);
        $reservations = $this->repo->reservations($ids, $from, $to);

        return [
            'success'      => true,
            'filters'      => $this->filtersOut($scope),
            'staff'        => $this->staff($this->repo->staffSales($ids, $from, $to)),
            'waste'        => $this->waste(
                $servings,
                $this->repo->spoilageLogValues($ids, $from, $to),
                $this->repo->spoilageByReason($ids, $from, $to),
                $this->repo->spoilageTotal($ids, $scope['prev_from'], $scope['prev_to'])
            ),
            'ingredients'  => $this->repo->ingredientUsage($ids, $from, $to)
                ->map(fn ($row) => [
                    'name'     => $row->name,
                    'unit'     => $row->unit,
                    'quantity' => round((float) $row->quantity, 2),
                    'items'    => (int) $row->items,
                ])
                ->sortByDesc('quantity')
                ->values()
                ->take(12)
                ->all(),
            'reservations' => $this->reservationStats($reservations),
        ];
    }

    private function staff(Collection $rows): array
    {
        return $rows
            ->map(function ($row) {
                $orders = (int) $row->orders;
                $name   = trim(($row->firstname ?? '') . ' ' . ($row->lastname ?? ''));

                return [
                    'uuid'            => $row->uuid,
                    'name'            => $row->uuid ? ($name !== '' ? $name : 'Unnamed staff') : 'No staff recorded',
                    'position'        => $row->position,
                    'branch'          => $row->branch_name,
                    'orders'          => $orders,
                    'revenue'         => round((float) $row->revenue, 2),
                    'avg_order_value' => $orders > 0 ? round((float) $row->revenue / $orders, 2) : 0,
                    'discounts'       => round((float) $row->discounts, 2),
                ];
            })
            ->sortByDesc('revenue')
            ->values()
            ->all();
    }

    /**
     * Planned vs sold vs spoiled. Spoilage value is each entry's size price kept
     * when it was logged; spoilage with no entry behind it (recorded before
     * entries existed) is valued at the item's base price. Add-ons aren't counted.
     */
    private function waste(Collection $servings, Collection $logValues, Collection $byReason, int $previousSpoiled): array
    {
        $servings = $servings->each(function ($row) use ($logValues) {
            $logged = $logValues->get($row->uuid);
            $loose  = max(0, (int) $row->spoilage - (int) ($logged->quantity ?? 0));

            $row->spoilage_value = (float) ($logged->value ?? 0) + $loose * (float) $row->base_price;
        });

        $expected = (int) $servings->sum('expected');
        $sold     = (int) $servings->sum('sold');
        $spoiled  = (int) $servings->sum('spoilage');

        return [
            'summary' => [
                'expected'        => $expected,
                'sold'            => $sold,
                'spoilage'        => $spoiled,
                'spoilage_value'  => round((float) $servings->sum('spoilage_value'), 2),
                'spoilage_change' => $spoiled === 0 && $previousSpoiled === 0 ? 0.0 : $this->change($spoiled, $previousSpoiled),
                // How much of what was planned actually sold.
                'sell_through'    => $expected > 0 ? round($sold / $expected * 100, 1) : null,
                'spoilage_rate'   => $expected > 0 ? round($spoiled / $expected * 100, 1) : null,
                'sold_out_days'   => (int) $servings->sum('sold_out_days'),
            ],
            'reasons' => $this->spoilageReasons($byReason, $spoiled),
            'voided'  => [
                'entries'  => (int) $byReason->where('voided', 1)->sum('entries'),
                'quantity' => (int) $byReason->where('voided', 1)->sum('quantity'),
            ],
            'items' => $servings
                ->map(fn ($row) => [
                    'uuid'           => $row->uuid,
                    'name'           => $row->menu_name,
                    'removed'        => (bool) $row->removed, // deleted from the menu since
                    'expected'       => (int) $row->expected,
                    'sold'           => (int) $row->sold,
                    'spoilage'       => (int) $row->spoilage,
                    'spoilage_value' => round((float) $row->spoilage_value, 2),
                    'sold_out_days'  => (int) $row->sold_out_days,
                    'days'          => (int) $row->days,
                    'sell_through'  => (int) $row->expected > 0 ? round((int) $row->sold / (int) $row->expected * 100, 1) : null,
                ])
                ->sortByDesc(fn (array $r) => [$r['spoilage'], $r['sold_out_days']])
                ->values()
                ->take(12)
                ->all(),
        ];
    }

    /**
     * Spoiled servings per reason, most first. Whatever the counted entries
     * don't explain (no reason picked, or spoilage recorded before entries
     * existed) is reported as 'unspecified', so the rows add up to the total.
     */
    private function spoilageReasons(Collection $byReason, int $spoiled): array
    {
        $rows = $byReason
            ->where('voided', 0)
            ->whereNotNull('reason')
            ->map(fn ($r) => ['reason' => $r->reason, 'quantity' => (int) $r->quantity]);

        $unspecified = $spoiled - $rows->sum('quantity');

        if ($unspecified > 0) {
            $rows->push(['reason' => 'unspecified', 'quantity' => $unspecified]);
        }

        return $rows
            ->map(fn (array $r) => $r + ['share' => $spoiled > 0 ? round($r['quantity'] / $spoiled * 100, 1) : 0])
            ->sortByDesc('quantity')
            ->values()
            ->all();
    }

    private function reservationStats(Collection $rows): array
    {
        $total    = $rows->count();
        $byStatus = $rows->countBy('status');
        // No-shows are measured against bookings whose outcome is known.
        $decided  = (int) ($byStatus['completed'] ?? 0) + (int) ($byStatus['no_show'] ?? 0) + (int) ($byStatus['seated'] ?? 0);
        $kept     = $rows->whereNotIn('status', ['cancelled', 'no_show']);

        $weekdays = array_fill(0, 7, 0);
        foreach ($rows as $r) {
            $weekdays[Carbon::parse($r->reservation_date)->dayOfWeek]++;
        }

        return [
            'total'        => $total,
            'by_status'    => collect(config('floor_plan.reservation_statuses'))
                ->map(fn ($s) => ['status' => $s, 'count' => (int) ($byStatus[$s] ?? 0)])
                ->all(),
            'guests'       => (int) $kept->sum('party_size'),
            'avg_party'    => $kept->count() > 0 ? round($kept->avg('party_size'), 1) : 0,
            'no_show_rate' => $decided > 0 ? round(($byStatus['no_show'] ?? 0) / $decided * 100, 1) : null,
            'cancel_rate'  => $total > 0 ? round(($byStatus['cancelled'] ?? 0) / $total * 100, 1) : null,
            'by_weekday'   => collect($weekdays)->map(fn ($n, $d) => ['weekday' => $d, 'count' => $n])->values()->all(),
        ];
    }

    // ── Shared ───────────────────────────────────────────────────────────

    /**
     * Resolves the window, the comparison window and the branch ids for one owner.
     */
    private function scope(User $owner, array $filters): array
    {
        $to   = isset($filters['to']) ? Carbon::parse($filters['to'])->endOfDay() : Carbon::today()->endOfDay();
        $from = isset($filters['from']) ? Carbon::parse($filters['from'])->startOfDay() : $to->copy()->subDays(29)->startOfDay();

        $days     = (int) $from->diffInDays($to->copy()->startOfDay()) + 1;
        $prevTo   = $from->copy()->subDay()->endOfDay();
        $prevFrom = $prevTo->copy()->subDays($days - 1)->startOfDay();

        $branches = $this->repo->branchesByOwner($owner->user_id);
        $branch   = null;

        if (! empty($filters['branch'])) {
            $branch = $branches->firstWhere('uuid', $filters['branch']);

            if (! $branch) {
                return ['error' => ['success' => false, 'http' => 404, 'message' => 'Branch not found.']];
            }
        }

        return [
            'from'       => $from,
            'to'         => $to,
            'days'       => $days,
            'prev_from'  => $prevFrom,
            'prev_to'    => $prevTo,
            'branches'   => $branches,
            'branch'     => $branch,
            'branch_ids' => $branch ? collect([$branch->branch_id]) : $branches->pluck('branch_id'),
            'cafe_ids'   => $branch ? collect([$branch->cafe_id]) : $branches->pluck('cafe_id')->unique()->values(),
        ];
    }

    private function filtersOut(array $scope): array
    {
        return [
            'from'          => $scope['from']->toDateString(),
            'to'            => $scope['to']->toDateString(),
            'days'          => $scope['days'],
            'previous_from' => $scope['prev_from']->toDateString(),
            'previous_to'   => $scope['prev_to']->toDateString(),
            'branch'        => $scope['branch'] ? ['uuid' => $scope['branch']->uuid, 'name' => $scope['branch']->branch_name] : null,
        ];
    }

    /**
     * Day buckets up to a month, Sunday-to-Saturday weeks up to six months, months beyond.
     */
    private function granularity(Carbon $from, Carbon $to): string
    {
        $days = (int) $from->diffInDays($to->copy()->startOfDay()) + 1;

        return match (true) {
            $days <= 31  => 'day',
            $days <= 183 => 'week',
            default      => 'month',
        };
    }

    /**
     * Rolls per-day totals into buckets, clipped to the window; empty buckets stay at zero.
     */
    private function series(string $granularity, Collection $days, Carbon $from, Carbon $to): array
    {
        $buckets = [];
        $cursor  = $from->copy();

        while ($cursor->lte($to)) {
            $end = match ($granularity) {
                'day'   => $cursor->copy()->endOfDay(),
                'week'  => $cursor->copy()->endOfWeek(Carbon::SATURDAY),
                'month' => $cursor->copy()->endOfMonth(),
            };
            if ($end->gt($to)) {
                $end = $to->copy();
            }

            $revenue = 0.0;
            $orders  = 0;
            for ($d = $cursor->copy(); $d->lte($end); $d->addDay()) {
                $row = $days->get($d->toDateString());
                if ($row) {
                    $revenue += (float) $row->revenue;
                    $orders  += (int) $row->orders;
                }
            }

            $buckets[] = [
                'start'   => $cursor->toDateString(),
                'end'     => $end->toDateString(),
                'revenue' => round($revenue, 2),
                'orders'  => $orders,
            ];

            $cursor = $end->copy()->addDay()->startOfDay();
        }

        return $buckets;
    }

    /**
     * 7 x 24 grid (weekday 0 = Sunday), every cell present.
     */
    private function heatmap(Collection $rows): array
    {
        $grid = [];
        for ($d = 0; $d < 7; $d++) {
            $grid[$d] = array_fill(0, 24, ['orders' => 0, 'revenue' => 0.0]);
        }

        foreach ($rows as $row) {
            $grid[(int) $row->weekday][(int) $row->hour] = [
                'orders'  => (int) $row->orders,
                'revenue' => round((float) $row->revenue, 2),
            ];
        }

        return $grid;
    }

    private function withShare(Collection $rows, string $key): array
    {
        $total = max(1, $rows->sum($key));

        return $rows
            ->map(fn (array $r) => [...$r, 'share' => round($r[$key] / $total * 100, 1)])
            ->sortByDesc($key)
            ->values()
            ->all();
    }

    private function change(float $current, float $previous): ?float
    {
        if ($previous <= 0) {
            return null;
        }

        return round(($current - $previous) / $previous * 100, 1);
    }
}
