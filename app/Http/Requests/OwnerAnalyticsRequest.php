<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;

/**
 * GET /api/owner/analytics/{sales|menu|branches|operations}
 *   from, to: Y-m-d, inclusive (default: the last 30 days)
 *   branch:   one branch uuid (default: every branch)
 */
class OwnerAnalyticsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'from'   => ['nullable', 'date_format:Y-m-d', 'required_with:to', 'before_or_equal:to'],
            'to'     => ['nullable', 'date_format:Y-m-d', 'required_with:from', 'before_or_equal:today'],
            'branch' => ['nullable', 'string', 'max:64'],
        ];
    }

    public function after(): array
    {
        return [
            function (Validator $validator) {
                $from = $this->input('from');
                $to   = $this->input('to');

                if ($from && $to && ! $validator->errors()->hasAny(['from', 'to'])
                    && \Illuminate\Support\Carbon::parse($from)->diffInDays(\Illuminate\Support\Carbon::parse($to)) > 365) {
                    $validator->errors()->add('from', 'Pick a range of one year or less.');
                }
            },
        ];
    }

    protected function failedValidation(Validator $validator): never
    {
        throw new HttpResponseException(
            response()->json([
                'success' => false,
                'message' => 'Validation failed.',
                'errors'  => $validator->errors(),
            ], 422)
        );
    }
}
