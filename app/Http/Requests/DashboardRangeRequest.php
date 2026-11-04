<?php

namespace App\Http\Requests;

use App\Http\Requests\Concerns\RespondsWithFirstJsonError;
use Illuminate\Foundation\Http\FormRequest;

/**
 * GET /api/owner/dashboard-stats?range=daily|weekly|monthly
 * GET /api/manager/branches/{branchUuid}/dashboard-stats?range=daily|weekly|monthly
 */
class DashboardRangeRequest extends FormRequest
{
    use RespondsWithFirstJsonError;

    public function rules(): array
    {
        return [
            'range' => ['nullable', 'in:daily,weekly,monthly'],
        ];
    }

    public function range(): string
    {
        return $this->validated('range') ?? 'daily';
    }
}
