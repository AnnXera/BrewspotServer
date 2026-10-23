<?php

namespace App\Http\Requests;

use App\Repository\OwnerAnalyticsRepository;
use Illuminate\Validation\Rule;

/**
 * GET /api/owner/analytics/transactions
 *   from, to, branch: as every analytics call
 *   status:           settled | refunded | void (default: every status)
 *   search:           receipt number, cashier name, or note text
 *   page, per_page:   paging (default 20 per page, at most 100)
 */
class OwnerTransactionLogRequest extends OwnerAnalyticsRequest
{
    public function rules(): array
    {
        return [
            ...parent::rules(),
            'status'   => ['nullable', 'string', Rule::in(array_keys(OwnerAnalyticsRepository::LOG_STATUSES))],
            'search'   => ['nullable', 'string', 'max:100'],
            'page'     => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ];
    }
}
