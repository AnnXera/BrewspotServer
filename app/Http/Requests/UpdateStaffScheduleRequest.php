<?php

namespace App\Http\Requests;

use App\Http\Requests\Concerns\StaffRequestHelpers;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/**
 * PUT /api/{owner|manager}/branches/{branchUuid}/staff/{userUuid}/schedule
 * Replaces the whole weekly schedule for this branch.
 */
class UpdateStaffScheduleRequest extends FormRequest
{
    use StaffRequestHelpers;

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return $this->scheduleRules(required: true);
    }

    public function after(): array
    {
        return [fn (Validator $validator) => $this->validateScheduleTimes($validator)];
    }
}
