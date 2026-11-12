<?php

namespace App\Services;

use App\Models\ActivityLog;
use App\Models\CafeBranch;
use App\Models\CafeStaff;
use App\Models\PosDevice;
use App\Models\User;
use App\Repository\ActivityLogRepository;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

/**
 * A cafe's audit log: who signed in or out, who unlocked or locked a register,
 * who changed today's servings, who changed a floor plan, and who added or edited an employee. The owner reads it on the Activity Log page.
 *
 * Writing a log entry never fails the action it describes: errors are reported
 * to the app log and swallowed.
 */
class ActivityLogService
{
    /** Role filter values on the owner page, mapped to role names. */
    public const ROLE_FILTERS = [
        'owner'   => 'Cafe Owner',
        'manager' => 'Manager',
        'cashier' => 'Cashier',
    ];

    public function __construct(
        private readonly ActivityLogRepository $repo
    ) {}

    // ── Writing ──────────────────────────────────────────────────────────

    public function login(User $user): void
    {
        $this->forUser($user, ActivityLog::CATEGORY_AUTH, ActivityLog::AUTH_LOGIN, 'Signed in to the dashboard');
    }

    public function logout(User $user): void
    {
        $this->forUser($user, ActivityLog::CATEGORY_AUTH, ActivityLog::AUTH_LOGOUT, 'Signed out of the dashboard');
    }

    public function posUnlock(PosDevice $device, CafeStaff $assignment): void
    {
        $this->forDevice($device, $assignment, ActivityLog::POS_UNLOCK, "Signed in on register \"{$device->name}\"");
    }

    /** @param  string  $reason  locked | switched (another person signed in on the register) */
    public function posLock(PosDevice $device, CafeStaff $assignment, string $reason = 'locked'): void
    {
        $description = $reason === 'switched'
            ? "Signed out of register \"{$device->name}\" (another person signed in)"
            : "Signed out of register \"{$device->name}\"";

        $this->forDevice($device, $assignment, ActivityLog::POS_LOCK, $description, ['reason' => $reason]);
    }

    public function serving(User $actor, CafeBranch $branch, string $action, string $description, array $meta = []): void
    {
        $this->forBranch($actor, $branch, ActivityLog::CATEGORY_SERVINGS, $action, $description, $meta);
    }

    public function floorPlan(User $actor, CafeBranch $branch, string $action, string $description, array $meta = []): void
    {
        $this->forBranch($actor, $branch, ActivityLog::CATEGORY_FLOOR_PLANS, $action, $description, $meta);
    }

    /**
     * A menu change. Cafe-wide (adding, editing or deleting an item or category) when
     * $branch is null; tied to one branch for availability at that branch.
     */
    public function menu(User $actor, int $cafeId, ?CafeBranch $branch, string $action, string $description, array $meta = []): void
    {
        $this->write([
            'cafe_id'     => $cafeId,
            'branch_id'   => $branch?->branch_id,
            ...$this->actor($actor),
            'category'    => ActivityLog::CATEGORY_MENU,
            'action'      => $action,
            'description' => $description,
            'meta'        => $meta ?: null,
        ]);
    }

    /** Something done to an employee's record at a branch. The employee is named in `meta.employee`. */
    public function employee(User $actor, CafeBranch $branch, User $employee, string $action, string $description, array $meta = []): void
    {
        $this->forBranch($actor, $branch, ActivityLog::CATEGORY_EMPLOYEES, $action, $description, [
            'employee' => [
                'uuid' => $employee->uuid,
                'name' => trim("{$employee->firstname} {$employee->lastname}"),
                'role' => $employee->roleName(),
            ],
            ...$meta,
        ]);
    }

    private function forBranch(User $actor, CafeBranch $branch, string $category, string $action, string $description, array $meta): void
    {
        $this->write([
            'cafe_id'     => $branch->cafe_id,
            'branch_id'   => $branch->branch_id,
            ...$this->actor($actor),
            'category'    => $category,
            'action'      => $action,
            'description' => $description,
            'meta'        => $meta ?: null,
        ]);
    }

    /**
     * Dashboard sign-in or sign-out. Owners are logged against their cafe; staff against
     * the branch they work at. Admins and people with no cafe are not logged.
     */
    private function forUser(User $user, string $category, string $action, string $description): void
    {
        $cafeId   = null;
        $branchId = null;

        if ($user->isOwner()) {
            $cafeId = $this->repo->cafeIdsByOwner($user->user_id)->first();
        } elseif ($user->isEmployee()) {
            $assignment = $user->activeStaffAssignments()->with('branch')->first()
                ?? $user->staffAssignments()->with('branch')->latest('staff_id')->first();

            $branchId = $assignment?->branch_id;
            $cafeId   = $assignment?->branch?->cafe_id;
        }

        if (! $cafeId) {
            return;
        }

        $this->write([
            'cafe_id'     => $cafeId,
            'branch_id'   => $branchId,
            ...$this->actor($user),
            'category'    => $category,
            'action'      => $action,
            'description' => $description,
        ]);
    }

    private function forDevice(PosDevice $device, CafeStaff $assignment, string $action, string $description, array $meta = []): void
    {
        $device->loadMissing('branch');
        $assignment->loadMissing('user.role');

        if (! $device->branch || ! $assignment->user) {
            return;
        }

        $this->write([
            'cafe_id'     => $device->branch->cafe_id,
            'branch_id'   => $device->branch_id,
            'device_id'   => $device->device_id,
            ...$this->actor($assignment->user),
            'category'    => ActivityLog::CATEGORY_POS,
            'action'      => $action,
            'description' => $description,
            'meta'        => $meta ?: null,
        ]);
    }

    private function actor(User $user): array
    {
        return [
            'user_id'    => $user->user_id,
            'actor_name' => trim("{$user->firstname} {$user->lastname}") ?: null,
            'actor_role' => $user->roleName(),
        ];
    }

    private function write(array $attributes): void
    {
        try {
            $this->repo->create([...$attributes, 'ip_address' => request()?->ip()]);
        } catch (\Throwable $e) {
            Log::warning('Activity log entry could not be written.', [
                'action' => $attributes['action'] ?? null,
                'error'  => $e->getMessage(),
            ]);
        }
    }

    // ── Reading (owner) ──────────────────────────────────────────────────

    /**
     * @param  array{from?: string, to?: string, branch?: string, category?: string, role?: string, search?: string, page?: int, per_page?: int}  $filters
     */
    public function listForOwner(User $owner, array $filters): array
    {
        $branches = $this->repo->branchesByOwner($owner->user_id);
        $branch   = null;

        if (! empty($filters['branch'])) {
            $branch = $branches->firstWhere('uuid', $filters['branch']);

            if (! $branch) {
                return ['success' => false, 'http' => 404, 'message' => 'Branch not found.'];
            }
        }

        // An owner filtering one branch still sees cafe-wide menu changes, as that branch's manager does.
        return $this->list($this->repo->cafeIdsByOwner($owner->user_id), $branch, $filters, true);
    }

    /**
     * A manager's view: everything logged at their branch, plus the cafe-wide menu changes
     * that affect it. IP addresses are left out.
     */
    public function listForBranch(CafeBranch $branch, array $filters): array
    {
        return $this->list(collect([$branch->cafe_id]), $branch, $filters, false);
    }

    private function list(\Illuminate\Support\Collection $cafeIds, ?CafeBranch $branch, array $filters, bool $withIp): array
    {
        $to   = isset($filters['to']) ? Carbon::parse($filters['to'])->endOfDay() : Carbon::today()->endOfDay();
        $from = isset($filters['from']) ? Carbon::parse($filters['from'])->startOfDay() : $to->copy()->subDays(29)->startOfDay();

        $scope = [
            'cafe_ids'             => $cafeIds,
            'branch_id'            => $branch?->branch_id,
            'cafe_wide_categories' => [ActivityLog::CATEGORY_MENU],
            'from'                 => $from,
            'to'                   => $to,
            'role'                 => isset($filters['role']) ? self::ROLE_FILTERS[$filters['role']] : null,
            'search'               => isset($filters['search']) ? trim($filters['search']) ?: null : null,
        ];

        $category = $filters['category'] ?? null;
        $page     = $this->repo->paginate($scope, $category, (int) ($filters['per_page'] ?? 20), (int) ($filters['page'] ?? 1));
        $counts   = $this->repo->countsByCategory($scope);

        return [
            'success' => true,
            'filters' => [
                'from'   => $from->toDateString(),
                'to'     => $to->toDateString(),
                'branch' => $branch ? ['uuid' => $branch->uuid, 'name' => $branch->branch_name] : null,
            ],
            'counts' => [
                'all' => $counts->sum(),
                ...collect(ActivityLog::CATEGORIES)->mapWithKeys(fn ($c) => [$c => $counts->get($c, 0)])->all(),
            ],
            'logs' => collect($page->items())->map(fn (ActivityLog $log) => [
                'uuid'        => $log->uuid,
                'created_at'  => $log->created_at?->toIso8601String(),
                'category'    => $log->category,
                'action'      => $log->action,
                'description' => $log->description,
                'actor'       => [
                    'name' => $log->actor_name,
                    'role' => $log->actor_role,
                ],
                'branch'      => $log->branch?->branch_name,
                'device'      => $log->device?->name,
                'ip_address'  => $withIp ? $log->ip_address : null,
                'meta'        => $log->meta,
            ])->values(),
            'pagination' => [
                'page'      => $page->currentPage(),
                'per_page'  => $page->perPage(),
                'total'     => $page->total(),
                'last_page' => $page->lastPage(),
            ],
        ];
    }
}
