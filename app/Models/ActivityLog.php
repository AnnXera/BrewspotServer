<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * One entry in a cafe's audit log. Rows are written once and never updated.
 */
class ActivityLog extends Model
{
    public const CATEGORY_AUTH     = 'auth';
    public const CATEGORY_POS      = 'pos';
    public const CATEGORY_SERVINGS = 'servings';

    public const CATEGORY_FLOOR_PLANS = 'floor_plans';
    public const CATEGORY_EMPLOYEES   = 'employees';
    public const CATEGORY_MENU        = 'menu';

    public const CATEGORIES = [
        self::CATEGORY_AUTH, self::CATEGORY_POS, self::CATEGORY_SERVINGS, self::CATEGORY_FLOOR_PLANS,
        self::CATEGORY_EMPLOYEES, self::CATEGORY_MENU,
    ];

    // Menu rows are cafe-wide (no branch) except the per-branch availability ones.
    public const MENU_ITEM_CREATED          = 'menu.item_created';
    public const MENU_ITEM_UPDATED          = 'menu.item_updated';
    public const MENU_ITEM_DELETED          = 'menu.item_deleted';
    public const MENU_ITEM_AVAILABILITY     = 'menu.item_availability';
    public const MENU_CATEGORY_CREATED      = 'menu.category_created';
    public const MENU_CATEGORY_UPDATED      = 'menu.category_updated';
    public const MENU_CATEGORY_DELETED      = 'menu.category_deleted';
    public const MENU_CATEGORY_AVAILABILITY = 'menu.category_availability';

    public const EMPLOYEE_CREATED          = 'employee.created';
    public const EMPLOYEE_UPDATED          = 'employee.updated';
    public const EMPLOYEE_SCHEDULE_UPDATED = 'employee.schedule_updated';
    public const EMPLOYEE_PIN_RESET        = 'employee.pin_reset';
    public const EMPLOYEE_TERMINATED       = 'employee.terminated';

    public const FLOOR_PLAN_CREATED     = 'floor_plan.created';
    public const FLOOR_PLAN_UPDATED     = 'floor_plan.updated';
    public const FLOOR_PLAN_DELETED     = 'floor_plan.deleted';
    public const FLOOR_PLAN_LAYOUT_SAVED = 'floor_plan.layout_saved';
    public const FLOOR_PLAN_ACTIVATED   = 'floor_plan.activated';
    public const FLOOR_PLAN_DEACTIVATED = 'floor_plan.deactivated';

    public const AUTH_LOGIN  = 'auth.login';
    public const AUTH_LOGOUT = 'auth.logout';

    public const POS_UNLOCK = 'pos.unlock';
    public const POS_LOCK   = 'pos.lock';

    public const SERVING_CREATED        = 'serving.created';
    public const SERVING_UPDATED        = 'serving.updated';
    public const SERVING_DELETED        = 'serving.deleted';
    public const SERVING_CATEGORY_SAVED = 'serving.category_saved';

    public const UPDATED_AT = null;

    protected $table = 'activity_logs';
    protected $primaryKey = 'activity_log_id';

    protected $fillable = [
        'uuid',
        'cafe_id',
        'branch_id',
        'user_id',
        'device_id',
        'actor_name',
        'actor_role',
        'category',
        'action',
        'description',
        'meta',
        'ip_address',
    ];

    protected $casts = [
        'meta'       => 'array',
        'created_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::creating(fn ($log) => $log->uuid ??= (string) Str::uuid());
    }

    public function cafe(): BelongsTo
    {
        return $this->belongsTo(Cafe::class, 'cafe_id', 'cafe_id');
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(CafeBranch::class, 'branch_id', 'branch_id')->withTrashed();
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id', 'user_id');
    }

    public function device(): BelongsTo
    {
        return $this->belongsTo(PosDevice::class, 'device_id', 'device_id');
    }
}
