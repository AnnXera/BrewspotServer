<?php

namespace App\Services;

use App\Http\Resources\UserResource;
use App\Http\Resources\CafeResource;
use App\Http\Resources\CafeBranchResource;
use App\Http\Resources\BranchSummaryResource;
use App\Http\Resources\SubscriptionPlanResource;
use App\Http\Resources\SubscriptionResource;
use App\Models\User;
use App\Repository\OwnerProfileRepository;
use App\Repository\SubscriptionRepository;
use App\Support\Tin;
use Illuminate\Support\Facades\Log;

class OwnerProfileService
{
    public function __construct(
        private readonly OwnerProfileRepository $repo,
        private readonly SubscriptionRepository $subscriptionRepo,
        private readonly \App\Repository\PaymentRepository $paymentRepo
    ) {}

    /**
     * Records that the owner dismissed the first-login welcome modal.
     */
    public function markWelcomed(User $owner): array
    {
        if ($owner->welcomed_at === null) {
            $owner->forceFill(['welcomed_at' => now()])->save();
        }

        return ['success' => true];
    }

    public function getProfile(User $owner): array
    {
        $owner = $this->repo->findOwnerWithRole($owner->user_id);

        return [
            'success' => true,
            'user'    => new UserResource($owner),
        ];
    }

    public function getCafes(User $owner): array
    {
        $cafes = $this->repo->findCafesByOwner($owner->user_id);

        return [
            'success' => true,
            'cafes'   => CafeResource::collection($cafes),
        ];
    }

    /**
     * List view — name and picture only, for the branch card grid.
     * Paginated, 6 per page by default.
     */
    public function getBranches(User $owner, int $perPage = 6, ?string $search = null, ?string $status = null): array
    {
        $branches = $this->repo->findBranchesByOwner($owner->user_id, $perPage, $search, $status);
        $cafeId   = $owner->cafes()->value('cafe_id');
        $prefix   = $cafeId ? Tin::prefix(Tin::mainBranchTin($cafeId)) : null;

        return [
            'success'   => true,
            'cafe_name' => $this->repo->findCafeNameByOwner($owner->user_id),
            // New side branches must reuse this 9-digit TIN (XXX-XXX-XXX)
            'main_tin_prefix' => $prefix ? Tin::formatPrefix($prefix) : null,
            'branches'  => $branches->through(fn ($branch) => new BranchSummaryResource($branch)),
        ];
    }

    /**
     * Detail view — full branch info, documents, status, etc.
     */
    public function getBranch(User $owner, string $branchUuid): array
    {
        $branch = $this->repo->findBranchByUuid($owner->user_id, $branchUuid);

        if (! $branch) {
            Log::channel('owner')->warning('Branch not found or not owned by this user.', [
                'owner_uuid'  => $owner->uuid,
                'branch_uuid' => $branchUuid,
            ]);

            return [
                'success' => false,
                'message' => 'Branch not found.',
            ];
        }

        return [
            'success' => true,
            'branch'  => new CafeBranchResource($branch),
        ];
    }

    public function getCurrentPlan(User $owner): array
    {
        $subscription = $this->subscriptionRepo->findCurrentByUserId($owner->user_id);

        if (! $subscription) {
            Log::channel('owner')->warning('Owner checked current plan — no active subscription.', [
                'owner_uuid' => $owner->uuid,
            ]);

            return [
                'success'        => false,
                'message'        => 'You have no active subscription.',
                'renewal_offer'  => $this->buildRenewalOffer($owner),
            ];
        }

        Log::channel('owner')->info('Owner viewed current subscription.', [
            'owner_uuid'        => $owner->uuid,
            'subscription_uuid' => $subscription->uuid,
            'plan_name'         => $subscription->plan?->sub_name,
        ]);

        return [
            'success'      => true,
            'subscription' => new SubscriptionResource($subscription),
        ];
    }

    /**
     * What an owner with no running term should be offered when they come back.
     *
     * Payment only happens once a term runs out, so the plan the owner settled on before
     * that point has to survive the lapse — otherwise a booked upgrade quietly disappears
     * and they are dropped back onto a bare plan list with nothing to pay for.
     *
     * A booked plan change wins over the plan that just ended, since that is the plan the
     * owner chose to move to.
     */
    private function buildRenewalOffer(User $owner): ?array
    {
        $ended = $this->subscriptionRepo->findLatestEndedByUserId($owner->user_id);

        if (! $ended) {
            return null;
        }

        $plan = $ended->pendingPlan ?? $ended->plan;

        if (! $plan) {
            return null;
        }

        return [
            'plan'           => new SubscriptionPlanResource($plan),
            'billing_cycle'  => $ended->pending_billing_cycle ?? $ended->billing_cycle ?? 'monthly',
            'was_scheduled'  => $ended->pendingPlan !== null,
            'previous_plan'  => $ended->plan?->sub_name,
            'ended_on'       => $ended->end_date?->toISOString(),
        ];
    }

    public function getPlanHistory(User $owner, int $perPage = 15)
    {
        Log::channel('owner')->info('Owner viewed payment history.', [
            'owner_uuid' => $owner->uuid,
            'per_page'   => $perPage,
        ]);

        $history = $this->paymentRepo->getSubscriptionPaymentsByUserId($owner->user_id, $perPage);

        return $history->through(fn ($payment) => new \App\Http\Resources\PaymentResource($payment));
    }

    public function getDashboardStats(User $owner, string $range = 'daily'): array
    {
        $branches = \App\Models\CafeBranch::whereHas('cafe', fn ($q) => $q->where('user_id', $owner->user_id))
            ->get();

        $branchIds = $branches->pluck('branch_id')->toArray();

        // Branch counts
        $branchesCount = $branches->count();
        $activeBranchesCount = $branches->where('status', 'active')->count();
        $pendingBranchesCount = $branches->where('status', 'pending_approval')->count();

        // Total lifetime earnings and orders
        $totalEarnings = (float) \App\Models\Transaction::whereIn('branch_id', $branchIds)
            ->where('status', 'completed')
            ->sum('total_amount');

        $totalOrders = (int) \App\Models\Transaction::whereIn('branch_id', $branchIds)
            ->where('status', 'completed')
            ->count();

        // Today's revenue and orders
        $todayTransactions = \App\Models\Transaction::whereIn('branch_id', $branchIds)
            ->where('status', 'completed')
            ->whereDate('created_at', \Carbon\Carbon::today())
            ->get();

        $todayRevenue = (float) $todayTransactions->sum('total_amount');
        $todayOrders = (int) $todayTransactions->count();

        // Range window calculation: daily | weekly | monthly
        $now = \Carbon\Carbon::now();
        $series = [];

        if ($range === 'weekly') {
            // 12 weeks: from 11 weeks ago to this week
            $periodFrom = $now->copy()->subWeeks(11)->startOfWeek();
            $periodTo = $now->copy()->endOfWeek();
            $prevFrom = $periodFrom->copy()->subWeeks(12);
            $prevTo = $periodFrom->copy()->subSecond();

            for ($w = 11; $w >= 0; $w--) {
                $wStart = $now->copy()->subWeeks($w)->startOfWeek();
                $wEnd = $now->copy()->subWeeks($w)->endOfWeek();

                $txs = \App\Models\Transaction::whereIn('branch_id', $branchIds)
                    ->where('status', 'completed')
                    ->whereBetween('created_at', [$wStart->toDateTimeString(), $wEnd->toDateTimeString()])
                    ->get();

                $series[] = [
                    'start' => $wStart->toDateString(),
                    'end' => $wEnd->toDateString(),
                    'label' => 'Wk ' . $wStart->format('W'),
                    'revenue' => (float) $txs->sum('total_amount'),
                    'orders' => (int) $txs->count(),
                    'upcoming' => false,
                ];
            }
        } elseif ($range === 'monthly') {
            // 12 months: from 11 months ago to this month
            $periodFrom = $now->copy()->subMonths(11)->startOfMonth();
            $periodTo = $now->copy()->endOfMonth();
            $prevFrom = $periodFrom->copy()->subMonths(12);
            $prevTo = $periodFrom->copy()->subSecond();

            for ($m = 11; $m >= 0; $m--) {
                $mStart = $now->copy()->subMonths($m)->startOfMonth();
                $mEnd = $now->copy()->subMonths($m)->endOfMonth();

                $txs = \App\Models\Transaction::whereIn('branch_id', $branchIds)
                    ->where('status', 'completed')
                    ->whereBetween('created_at', [$mStart->toDateTimeString(), $mEnd->toDateTimeString()])
                    ->get();

                $series[] = [
                    'start' => $mStart->toDateString(),
                    'end' => $mEnd->toDateString(),
                    'label' => $mStart->format('M Y'),
                    'revenue' => (float) $txs->sum('total_amount'),
                    'orders' => (int) $txs->count(),
                    'upcoming' => false,
                ];
            }
        } else {
            // Default: 'daily' -> 7 days of the current week (Monday to Sunday)
            $range = 'daily';
            $periodFrom = $now->copy()->startOfWeek();
            $periodTo = $now->copy()->endOfWeek();
            $prevFrom = $periodFrom->copy()->subWeek();
            $prevTo = $periodTo->copy()->subWeek();

            for ($d = 0; $d < 7; $d++) {
                $day = $periodFrom->copy()->addDays($d);
                $isUpcoming = $day->isAfter($now->copy()->endOfDay());

                $txs = $isUpcoming
                    ? collect()
                    : \App\Models\Transaction::whereIn('branch_id', $branchIds)
                        ->where('status', 'completed')
                        ->whereDate('created_at', $day->toDateString())
                        ->get();

                $series[] = [
                    'start' => $day->toDateString(),
                    'end' => $day->toDateString(),
                    'label' => $day->format('D j'),
                    'revenue' => (float) $txs->sum('total_amount'),
                    'orders' => (int) $txs->count(),
                    'upcoming' => $isUpcoming,
                ];
            }
        }

        // Period transactions and totals
        $periodTransactions = \App\Models\Transaction::whereIn('branch_id', $branchIds)
            ->where('status', 'completed')
            ->whereBetween('created_at', [$periodFrom->toDateTimeString(), $periodTo->toDateTimeString()])
            ->get();

        $periodRevenue = (float) $periodTransactions->sum('total_amount');
        $periodOrders = (int) $periodTransactions->count();
        $avgOrderValue = $periodOrders > 0 ? (float) ($periodRevenue / $periodOrders) : 0.0;

        // Previous period totals for comparison
        $prevTransactions = \App\Models\Transaction::whereIn('branch_id', $branchIds)
            ->where('status', 'completed')
            ->whereBetween('created_at', [$prevFrom->toDateTimeString(), $prevTo->toDateTimeString()])
            ->get();

        $prevRevenue = (float) $prevTransactions->sum('total_amount');
        $prevOrders = (int) $prevTransactions->count();

        $revenueChange = $prevRevenue > 0
            ? (float) round((($periodRevenue - $prevRevenue) / $prevRevenue) * 100, 1)
            : null;

        $ordersChange = $prevOrders > 0
            ? (float) round((($periodOrders - $prevOrders) / $prevOrders) * 100, 1)
            : null;

        // Branch Breakdown for the period
        $branchBreakdown = $branches->map(function ($b) use ($periodTransactions, $periodRevenue) {
            $branchTxs = $periodTransactions->where('branch_id', $b->branch_id);
            $rev = (float) $branchTxs->sum('total_amount');
            $ord = (int) $branchTxs->count();
            $share = $periodRevenue > 0 ? (float) round(($rev / $periodRevenue) * 100, 1) : 0.0;

            return [
                'uuid' => $b->uuid,
                'name' => $b->branch_name,
                'branch_type' => $b->branch_type ?? 'main',
                'status' => $b->status,
                'revenue' => $rev,
                'orders' => $ord,
                'share' => $share,
            ];
        })->sortByDesc('revenue')->values()->toArray();

        // Trending Items in the period
        $periodTxIds = $periodTransactions->pluck('transaction_id')->toArray();

        $trendingItems = [];
        if (!empty($periodTxIds)) {
            $topItemsQuery = \Illuminate\Support\Facades\DB::table('transaction_items')
                ->join('menu_items', 'transaction_items.men_item_id', '=', 'menu_items.men_item_id')
                ->leftJoin('menu_categories', 'menu_items.men_category_id', '=', 'menu_categories.men_category_id')
                ->whereIn('transaction_items.transaction_id', $periodTxIds)
                ->select(
                    'menu_items.uuid as uuid',
                    'menu_items.menu_name as name',
                    'menu_categories.name as category',
                    \Illuminate\Support\Facades\DB::raw('COUNT(DISTINCT transaction_items.transaction_id) as orders'),
                    \Illuminate\Support\Facades\DB::raw('SUM(transaction_items.quantity) as quantity'),
                    \Illuminate\Support\Facades\DB::raw('SUM(transaction_items.quantity * transaction_items.unit_price) as sales')
                )
                ->groupBy('menu_items.men_item_id', 'menu_items.uuid', 'menu_items.menu_name', 'menu_categories.name')
                ->orderByDesc('orders')
                ->take(5)
                ->get();

            $trendingItems = $topItemsQuery->map(function ($item, $idx) use ($periodOrders) {
                $ordersCount = (int) $item->orders;
                $share = $periodOrders > 0 ? (float) round(($ordersCount / $periodOrders) * 100, 1) : 0.0;

                return [
                    'rank' => $idx + 1,
                    'uuid' => (string) $item->uuid,
                    'name' => (string) $item->name,
                    'category' => (string) ($item->category ?? 'Uncategorized'),
                    'orders' => $ordersCount,
                    'quantity' => (int) $item->quantity,
                    'sales' => (float) $item->sales,
                    'share' => $share,
                ];
            })->toArray();
        }

        // Top Categories in the period
        $topCategories = [];
        if (!empty($periodTxIds)) {
            $catQuery = \Illuminate\Support\Facades\DB::table('transaction_items')
                ->join('menu_items', 'transaction_items.men_item_id', '=', 'menu_items.men_item_id')
                ->leftJoin('menu_categories', 'menu_items.men_category_id', '=', 'menu_categories.men_category_id')
                ->whereIn('transaction_items.transaction_id', $periodTxIds)
                ->select(
                    'menu_categories.uuid as uuid',
                    \Illuminate\Support\Facades\DB::raw("COALESCE(menu_categories.name, 'Uncategorized') as name"),
                    \Illuminate\Support\Facades\DB::raw('COUNT(DISTINCT transaction_items.transaction_id) as orders'),
                    \Illuminate\Support\Facades\DB::raw('SUM(transaction_items.quantity) as quantity'),
                    \Illuminate\Support\Facades\DB::raw('SUM(transaction_items.quantity * transaction_items.unit_price) as sales'),
                    \Illuminate\Support\Facades\DB::raw('COUNT(DISTINCT menu_items.men_item_id) as items')
                )
                ->groupBy('menu_categories.men_category_id', 'menu_categories.uuid', 'menu_categories.name')
                ->orderByDesc('orders')
                ->take(5)
                ->get();

            $topCategories = $catQuery->map(function ($cat) use ($periodOrders) {
                $ordersCount = (int) $cat->orders;
                $share = $periodOrders > 0 ? (float) round(($ordersCount / $periodOrders) * 100, 1) : 0.0;

                return [
                    'uuid' => $cat->uuid ? (string) $cat->uuid : null,
                    'name' => (string) $cat->name,
                    'orders' => $ordersCount,
                    'quantity' => (int) $cat->quantity,
                    'sales' => (float) $cat->sales,
                    'items' => (int) $cat->items,
                    'share' => $share,
                ];
            })->toArray();
        }

        return [
            'success' => true,
            'generated_at' => $now->toISOString(),
            'totals' => [
                'branches' => $branchesCount,
                'active_branches' => $activeBranchesCount,
                'pending_branches' => $pendingBranchesCount,
                'orders' => $totalOrders,
                'revenue' => $totalEarnings,
                'today_revenue' => $todayRevenue,
                'today_orders' => $todayOrders,
            ],
            'period' => [
                'range' => $range,
                'from' => $periodFrom->toDateString(),
                'to' => $periodTo->toDateString(),
                'revenue' => $periodRevenue,
                'orders' => $periodOrders,
                'avg_order_value' => $avgOrderValue,
                'previous_revenue' => $prevRevenue,
                'previous_orders' => $prevOrders,
                'revenue_change' => $revenueChange,
                'orders_change' => $ordersChange,
            ],
            'series' => $series,
            'branches' => $branchBreakdown,
            'trending_items' => $trendingItems,
            'top_categories' => $topCategories,

            // Keep legacy fields as well for backward compatibility
            'total_earnings' => $totalEarnings,
            'detailed_stats' => [
                'today_revenue' => $todayRevenue,
                'today_orders' => $todayOrders,
                'avg_order_value' => $avgOrderValue,
                'active_customers' => $todayOrders,
            ],
        ];
    }
}