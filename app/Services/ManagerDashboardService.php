<?php

namespace App\Services;

use App\Models\CafeBranch;
use App\Models\CafeStaff;
use App\Models\Payment;
use App\Models\Reservation;
use App\Models\Transaction;
use App\Repository\OwnerDashboardRepository;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Manager dashboard: branch-scoped figures matching the owner dashboard aesthetics,
 * with deep dive sections for reservations, employees, and transaction history.
 */
class ManagerDashboardService
{
    private const BUCKETS = [
        'daily'   => 7, // Sunday to Saturday
        'weekly'  => 12,
        'monthly' => 12,
    ];

    public function __construct(
        private readonly OwnerDashboardRepository $ownerRepo
    ) {}

    public function stats(CafeBranch $branch, string $range): array
    {
        $now       = Carbon::now();
        $branchIds = collect([$branch->branch_id]);

        [$from, $to] = $this->window($range, $now);
        [$prevFrom]  = $this->window($range, $this->shift($range, $now, -self::BUCKETS[$range]));
        $prevTo      = $this->shift($range, $now, -self::BUCKETS[$range])->endOfDay();

        $series       = $this->series($range, $this->ownerRepo->dailyTotals($branchIds, $from, $to), $from);
        $previousDays = $this->ownerRepo->dailyTotals($branchIds, $prevFrom, $prevTo);

        $revenue     = $series->sum('revenue');
        $orders      = $series->sum('orders');
        $prevRevenue = (float) $previousDays->sum('revenue');
        $prevOrders  = (int) $previousDays->sum('orders');

        $items = $this->ownerRepo->itemSales($branchIds, $from, $to);
        $today = $this->ownerRepo->dailyTotals($branchIds, $now->copy()->startOfDay(), $now->copy()->endOfDay())->first();
        $lifetime = $this->ownerRepo->lifetimeTotals($branchIds);

        // Branch reservations data
        $reservationsData = $this->branchReservations($branch, $now);

        // Branch employees data
        $employeesData = $this->branchEmployees($branch, $now);

        // Recent transactions
        $recentTransactions = $this->recentTransactions($branch);

        return [
            'success'      => true,
            'generated_at' => $now->toISOString(),
            'branch'       => [
                'uuid'        => $branch->uuid,
                'name'        => $branch->branch_name,
                'address'     => $branch->address,
                'branch_type' => $branch->branch_type,
                'status'      => $branch->status,
            ],
            'totals' => [
                'lifetime_orders'       => $lifetime['orders'] ?? 0,
                'lifetime_revenue'      => $lifetime['revenue'] ?? 0.0,
                'today_orders'          => (int) ($today->orders ?? 0),
                'today_revenue'         => (float) ($today->revenue ?? 0.0),
                'active_employees'      => $employeesData['summary']['active'],
                'total_employees'       => $employeesData['summary']['total'],
                'working_today'         => $employeesData['summary']['working_today'],
                'today_reservations'    => $reservationsData['summary']['today_count'],
                'upcoming_reservations' => $reservationsData['summary']['upcoming_count'],
                'pending_reservations'  => $reservationsData['summary']['pending_count'],
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
            'series'              => $series->values(),
            'trending_items'      => $this->trending($items, $orders),
            'top_categories'      => $this->categories($this->ownerRepo->categoryOrders($branchIds, $from, $to), $orders),
            'reservations'        => $reservationsData,
            'employees'           => $employeesData,
            'recent_transactions' => $recentTransactions,
        ];
    }

    private function branchReservations(CafeBranch $branch, Carbon $now): array
    {
        $baseQuery = Reservation::query()
            ->whereHas('table', fn ($t) => $t->withTrashed()->whereHas(
                'floorPlan', fn ($p) => $p->withTrashed()->where('branch_id', $branch->branch_id)
            ));

        $todayStart = $now->copy()->startOfDay();
        $todayEnd   = $now->copy()->endOfDay();

        $allRows = (clone $baseQuery)
            ->where('reservation_date', '>=', $todayStart->copy()->subDays(1))
            ->get(['reservation_id', 'reservation_date', 'status']);

        $todayCount     = $allRows->filter(fn ($r) => $r->reservation_date >= $todayStart && $r->reservation_date <= $todayEnd)->count();
        $upcomingCount  = (clone $baseQuery)->where('reservation_date', '>=', $now)->whereIn('status', ['pending', 'confirmed'])->count();
        $pendingCount   = (clone $baseQuery)->where('status', 'pending')->where('reservation_date', '>=', $todayStart)->count();
        $confirmedCount = (clone $baseQuery)->where('status', 'confirmed')->where('reservation_date', '>=', $todayStart)->count();
        $seatedCount    = (clone $baseQuery)->where('status', 'seated')->where('reservation_date', '>=', $todayStart)->count();
        $completedCount = (clone $baseQuery)->where('status', 'completed')->where('reservation_date', '>=', $todayStart)->count();
        $cancelledCount = (clone $baseQuery)->where('status', 'cancelled')->where('reservation_date', '>=', $todayStart)->count();

        $upcomingList = (clone $baseQuery)
            ->with(['table' => fn ($q) => $q->withTrashed(), 'creator'])
            ->where('reservation_date', '>=', $todayStart)
            ->orderBy('reservation_date', 'asc')
            ->take(8)
            ->get()
            ->map(fn (Reservation $r) => [
                'uuid'             => $r->uuid,
                'customer_name'    => $r->customer_name,
                'customer_phone'   => $r->customer_phone,
                'customer_email'   => $r->customer_email,
                'party_size'       => $r->party_size,
                'reservation_date' => $r->reservation_date?->toISOString(),
                'reservation_end'  => $r->reservation_end?->toISOString(),
                'table_name'       => $r->table?->table_name ?? 'Table',
                'status'           => $r->status,
                'notes'            => $r->notes,
                'created_by'       => $r->creator ? trim("{$r->creator->firstname} {$r->creator->lastname}") : null,
            ])
            ->values()
            ->all();

        return [
            'summary' => [
                'today_count'     => $todayCount,
                'upcoming_count'  => $upcomingCount,
                'pending_count'   => $pendingCount,
                'confirmed_count' => $confirmedCount,
                'seated_count'    => $seatedCount,
                'completed_count' => $completedCount,
                'cancelled_count' => $cancelledCount,
            ],
            'upcoming' => $upcomingList,
        ];
    }

    private function branchEmployees(CafeBranch $branch, Carbon $now): array
    {
        $staffRows = CafeStaff::query()
            ->where('branch_id', $branch->branch_id)
            ->with(['user.role', 'schedules'])
            ->get();

        $todayDayOfWeek = $now->dayOfWeek; // 0 = Sunday ... 6 = Saturday

        $total        = $staffRows->count();
        $active       = $staffRows->where('employment_status', CafeStaff::STATUS_ACTIVE)->count();
        $cashiers     = $staffRows->filter(fn ($s) => $s->user?->isCashier())->count();
        
        $workingToday = 0;
        $list = [];

        foreach ($staffRows as $s) {
            $user = $s->user;
            if (! $user) continue;

            $todaySchedule = $s->schedules->firstWhere('day_of_week', $todayDayOfWeek);
            $shiftStr = null;
            if ($todaySchedule) {
                if ($todaySchedule->is_day_off) {
                    $shiftStr = 'Day Off';
                } elseif ($todaySchedule->start_time && $todaySchedule->end_time) {
                    $workingToday++;
                    $shiftStr = Carbon::parse($todaySchedule->start_time)->format('g:i A') . ' - ' . Carbon::parse($todaySchedule->end_time)->format('g:i A');
                }
            }

            $list[] = [
                'uuid'           => $user->uuid,
                'name'           => trim("{$user->firstname} {$user->lastname}"),
                'email'          => $user->email,
                'role'           => $user->roleName() ?? 'Staff',
                'status'         => $s->employment_status,
                'has_pin'        => $user->hasPin(),
                'is_locked'      => $user->isPinLocked(),
                'today_shift'    => $shiftStr ?? 'No shift set',
                'is_active'      => $s->isActive(),
            ];
        }

        usort($list, function ($a, $b) {
            if ($a['is_active'] !== $b['is_active']) {
                return $b['is_active'] <=> $a['is_active'];
            }
            $rolePriority = ['Manager' => 1, 'Cashier' => 2, 'Staff' => 3];
            $pA = $rolePriority[$a['role']] ?? 4;
            $pB = $rolePriority[$b['role']] ?? 4;
            if ($pA !== $pB) return $pA <=> $pB;
            return strcmp($a['name'], $b['name']);
        });

        return [
            'summary' => [
                'total'         => $total,
                'active'        => $active,
                'cashiers'      => $cashiers,
                'working_today' => $workingToday,
            ],
            'list' => array_slice($list, 0, 10),
        ];
    }

    private function recentTransactions(CafeBranch $branch): array
    {
        $transactions = Transaction::query()
            ->where('branch_id', $branch->branch_id)
            ->with(['staff.user', 'payments'])
            ->withCount('items')
            ->orderBy('created_at', 'desc')
            ->take(10)
            ->get();

        return $transactions->map(function (Transaction $t) {
            $payment = $t->payments->sortByDesc('created_at')->first();
            return [
                'uuid'            => $t->uuid,
                'receipt_number'  => $t->receipt_number ?: str_pad((string) $t->transaction_id, 6, '0', STR_PAD_LEFT),
                'created_at'      => $t->created_at->toISOString(),
                'staff_name'      => $t->staff?->user ? trim("{$t->staff->user->firstname} {$t->staff->user->lastname}") : 'POS / Admin',
                'total_amount'    => (float) $t->total_amount,
                'status'          => $t->status,
                'items_count'     => $t->items_count,
                'payment_method'  => $payment?->payment_method_type ?? 'cash',
                'walk_in_note'    => $t->walk_in_note,
            ];
        })->values()->all();
    }

    public function transactions(CafeBranch $branch, array $filters, int $perPage = 15): array
    {
        $query = Transaction::query()
            ->where('branch_id', $branch->branch_id)
            ->with(['staff.user', 'payments'])
            ->withCount('items')
            ->orderBy('created_at', 'desc');

        if (! empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        if (! empty($filters['search'])) {
            $s = $filters['search'];
            $query->where(function (Builder $q) use ($s) {
                $q->where('receipt_number', 'like', "%{$s}%")
                  ->orWhere('walk_in_note', 'like', "%{$s}%")
                  ->orWhereHas('staff.user', function ($u) use ($s) {
                      $u->where('firstname', 'like', "%{$s}%")
                        ->orWhere('lastname', 'like', "%{$s}%");
                  });
            });
        }

        if (! empty($filters['from'])) {
            $query->whereDate('created_at', '>=', $filters['from']);
        }
        if (! empty($filters['to'])) {
            $query->whereDate('created_at', '<=', $filters['to']);
        }

        $paginator = $query->paginate($perPage);

        $today = Carbon::today();
        $todayTotals = Transaction::query()
            ->where('branch_id', $branch->branch_id)
            ->whereDate('created_at', $today)
            ->selectRaw("
                COUNT(*) as total_count,
                SUM(CASE WHEN status IN ('completed', 'paid') THEN 1 ELSE 0 END) as completed_count,
                SUM(CASE WHEN status = 'void' THEN 1 ELSE 0 END) as void_count,
                SUM(CASE WHEN status = 'refunded' THEN 1 ELSE 0 END) as refunded_count,
                COALESCE(SUM(CASE WHEN status IN ('completed', 'paid') THEN total_amount ELSE 0 END), 0) as completed_amount
            ")
            ->first();

        return [
            'success'      => true,
            'today_stats'  => [
                'total_count'      => (int) ($todayTotals->total_count ?? 0),
                'completed_count'  => (int) ($todayTotals->completed_count ?? 0),
                'void_count'       => (int) ($todayTotals->void_count ?? 0),
                'refunded_count'   => (int) ($todayTotals->refunded_count ?? 0),
                'completed_amount' => (float) ($todayTotals->completed_amount ?? 0),
            ],
            'transactions' => [
                'data' => $paginator->getCollection()->map(function (Transaction $t) {
                    $payment = $t->payments->sortByDesc('created_at')->first();
                    return [
                        'uuid'            => $t->uuid,
                        'receipt_number'  => $t->receipt_number ?: str_pad((string) $t->transaction_id, 6, '0', STR_PAD_LEFT),
                        'created_at'      => $t->created_at->toISOString(),
                        'staff_name'      => $t->staff?->user ? trim("{$t->staff->user->firstname} {$t->staff->user->lastname}") : 'POS / Admin',
                        'total_amount'    => (float) $t->total_amount,
                        'status'          => $t->status,
                        'items_count'     => $t->items_count,
                        'payment_method'  => $payment?->payment_method_type ?? 'cash',
                        'walk_in_note'    => $t->walk_in_note,
                    ];
                })->values()->all(),
                'current_page' => $paginator->currentPage(),
                'last_page'    => $paginator->lastPage(),
                'per_page'     => $paginator->perPage(),
                'total'        => $paginator->total(),
            ],
        ];
    }

    public function transaction(CafeBranch $branch, string $transactionUuid): array
    {
        $t = Transaction::where('branch_id', $branch->branch_id)
            ->where('uuid', $transactionUuid)
            ->with(['staff.user', 'items.menuItem', 'items.addons', 'payments'])
            ->first();

        if (! $t) {
            return ['success' => false, 'http' => 404, 'message' => 'Transaction not found.'];
        }

        $payment = $t->payments ? $t->payments->sortByDesc('created_at')->first() : null;
        $items = $t->items ?? collect();
        $subtotal = $items->sum(fn ($i) => (float) $i->unit_price * $i->quantity);

        return [
            'success'     => true,
            'transaction' => [
                'uuid'            => $t->uuid,
                'receipt_number'  => $t->receipt_number ?: str_pad((string) $t->transaction_id, 6, '0', STR_PAD_LEFT),
                'created_at'      => $t->created_at->toISOString(),
                'staff_name'      => $t->staff?->user ? trim("{$t->staff->user->firstname} {$t->staff->user->lastname}") : 'POS / Admin',
                'status'          => $t->status,
                'walk_in_note'    => $t->walk_in_note,
                'subtotal'        => round($subtotal, 2),
                'discount_type'   => $t->discount_type,
                'discount_amount' => (float) $t->discount_amount,
                'total_amount'    => (float) $t->total_amount,
                'payment'         => $payment ? [
                    'method'           => $payment->payment_method_type,
                    'reference_number' => $payment->payment_instrument,
                    'amount_tendered'  => (float) $payment->amount_tendered,
                    'change'           => (float) $payment->amount_change,
                ] : null,
                'items' => $items->map(fn ($i) => [
                    'uuid'       => $i->uuid,
                    'menu_name'  => $i->menuItem?->menu_name ?? ('Item #' . $i->men_item_id),
                    'size_name'  => $i->size_name,
                    'quantity'   => $i->quantity,
                    'unit_price' => (float) $i->unit_price,
                    'line_total' => round((float) $i->unit_price * $i->quantity, 2),
                    'addons'     => $i->addons->map(fn ($a) => [
                        'name'     => $a->addon_name,
                        'price'    => (float) $a->price,
                        'quantity' => (int) $a->quantity,
                    ])->values()->all(),
                ])->values()->all(),
            ],
        ];
    }

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

    private function shift(string $range, Carbon $date, int $steps): Carbon
    {
        return match ($range) {
            'daily'   => $date->copy()->addDays($steps),
            'weekly'  => $date->copy()->addWeeks($steps),
            'monthly' => $date->copy()->addMonthsNoOverflow($steps),
        };
    }

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
                'upcoming' => $start->gt($today),
            ]);
        }

        return $buckets;
    }

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

    private function change(float $current, float $previous): ?float
    {
        if ($previous <= 0) {
            return null;
        }

        return round(($current - $previous) / $previous * 100, 1);
    }
}
