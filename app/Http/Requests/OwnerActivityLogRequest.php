<?php

namespace App\Http\Requests;

use App\Models\ActivityLog;
use App\Services\ActivityLogService;
use Illuminate\Validation\Rule;

/**
 * GET /api/owner/activity-logs
 *   from, to, branch: as every analytics call (default: the last 30 days, every branch)
 *   category:         auth | pos | servings | floor_plans | employees | menu | reservations (default: every category)
 *   role:             owner | manager | cashier (default: everyone)
 *   search:           person's name or what they did
 *   page, per_page:   paging (default 20 per page, at most 100)
 */
class OwnerActivityLogRequest extends OwnerAnalyticsRequest
{
    public function rules(): array
    {
        return [
            ...parent::rules(),
            'category' => ['nullable', 'string', Rule::in(ActivityLog::CATEGORIES)],
            'role'     => ['nullable', 'string', Rule::in(array_keys(ActivityLogService::ROLE_FILTERS))],
            'search'   => ['nullable', 'string', 'max:100'],
            'page'     => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ];
    }
}
