<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class UpdateCafeOpeningHoursRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'hours'              => ['required', 'array', 'size:7'],
            'hours.*.day_of_week'=> ['required', 'string', 'in:Monday,Tuesday,Wednesday,Thursday,Friday,Saturday,Sunday'],
            'hours.*.is_closed'  => ['required', 'boolean'],
            'hours.*.open_time'  => ['nullable', 'date_format:H:i:s', 'required_if:hours.*.is_closed,false'],
            'hours.*.close_time' => ['nullable', 'date_format:H:i:s', 'required_if:hours.*.is_closed,false'],
        ];
    }

    public function messages(): array
    {
        return [
            'hours.size' => 'You must provide exactly 7 days of opening hours.',
        ];
    }

    protected function failedValidation(\Illuminate\Contracts\Validation\Validator $validator): never
    {
        throw new \Illuminate\Http\Exceptions\HttpResponseException(
            response()->json([
                'success' => false,
                'message' => 'Validation failed.',
                'errors'  => $validator->errors(),
            ], 422)
        );
    }
}
