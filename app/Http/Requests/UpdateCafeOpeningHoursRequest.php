<?php

namespace App\Http\Requests;

use App\Models\CafeOpeningHour;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Validation\Rule;

class UpdateCafeOpeningHoursRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $hours = $this->input('hours');

        if (! is_array($hours)) {
            return;
        }

        $this->merge(['hours' => array_map(function ($day) {
            if (! is_array($day)) {
                return $day;
            }
            foreach (['open_time', 'close_time'] as $key) {
                if (isset($day[$key]) && is_string($day[$key]) && preg_match('/^\d{2}:\d{2}:\d{2}$/', $day[$key])) {
                    $day[$key] = substr($day[$key], 0, 5);
                }
            }
            return $day;
        }, $hours)]);
    }

    public function rules(): array
    {
        return [
            'hours'               => ['required', 'array', 'size:7'],
            'hours.*.day_of_week' => ['required', 'string', 'distinct', Rule::in(CafeOpeningHour::DAYS)],
            'hours.*.is_closed'   => ['required', 'boolean'],
            'hours.*.is_24_hours' => ['sometimes', 'boolean'],
            'hours.*.open_time'   => ['nullable', 'date_format:H:i'],
            'hours.*.close_time'  => ['nullable', 'date_format:H:i'],
        ];
    }

    public function after(): array
    {
        return [function (Validator $validator) {
            foreach ((array) $this->input('hours', []) as $i => $day) {
                if (! is_array($day) || ! empty($day['is_closed']) || ! empty($day['is_24_hours'])) {
                    continue;
                }

                $name = $day['day_of_week'] ?? 'This day';

                if (empty($day['open_time']) || empty($day['close_time'])) {
                    $validator->errors()->add("hours.$i.open_time", "{$name}: set an opening and closing time, or mark it closed or 24 hours.");
                } elseif ($day['open_time'] === $day['close_time']) {
                    $validator->errors()->add("hours.$i.close_time", "{$name}: opening and closing time can't be the same. Use 24 hours instead.");
                }
            }
        }];
    }

    public function messages(): array
    {
        return [
            'hours.size'                   => 'You must provide exactly 7 days of opening hours.',
            'hours.*.day_of_week.distinct' => 'Each day can only appear once.',
            'hours.*.open_time.date_format'  => 'Times must be in HH:MM format.',
            'hours.*.close_time.date_format' => 'Times must be in HH:MM format.',
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
