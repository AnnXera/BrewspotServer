<?php

namespace App\Http\Requests;

use App\Models\FloorPlan;
use App\Http\Requests\Concerns\RespondsWithJsonErrors;
use Illuminate\Foundation\Http\FormRequest;

/**
 * POST /api/{owner|manager}/branches/{branchUuid}/floor-plans
 */
class StoreFloorPlanRequest extends FormRequest
{
    use RespondsWithJsonErrors;

    public function rules(): array
    {
        return [
            'floorplan_name' => ['required', 'string', 'max:100', $this->uniqueNameRule()],
            ...self::canvasRules(true),
        ];
    }

    public static function canvasRules(bool $required): array
    {
        $presence = $required ? 'required' : 'sometimes';
        $min      = config('floor_plan.limits.canvas_min');
        $max      = config('floor_plan.limits.canvas_max');

        return [
            'canvas_width'           => [$presence, 'numeric', "min:$min", "max:$max"],
            'canvas_height'          => [$presence, 'numeric', "min:$min", "max:$max"],
            'boundary_points'        => ['nullable', 'array', 'max:' . config('floor_plan.limits.max_boundary_points')],
            'boundary_points.*.x'    => ['required', 'numeric', 'min:0'],
            'boundary_points.*.y'    => ['required', 'numeric', 'min:0'],
        ];
    }

    /** Names are unique per branch, ignoring case. */
    protected function uniqueNameRule(?string $ignorePlanUuid = null): \Closure
    {
        return function (string $attribute, mixed $value, \Closure $fail) use ($ignorePlanUuid) {
            $branch = $this->attributes->get('branch');

            if (! $branch || ! is_string($value)) {
                return;
            }

            $taken = FloorPlan::where('branch_id', $branch->branch_id)
                ->whereRaw('LOWER(floorplan_name) = ?', [mb_strtolower(trim($value))])
                ->when($ignorePlanUuid, fn ($q) => $q->where('uuid', '!=', $ignorePlanUuid))
                ->exists();

            if ($taken) {
                $fail('This branch already has a floor plan with that name.');
            }
        };
    }

    public function messages(): array
    {
        return [
            'floorplan_name.required' => 'Give the floor plan a name, e.g. "Main Floor".',
            'canvas_width.min'        => 'The canvas is too small.',
            'canvas_height.min'       => 'The canvas is too small.',
        ];
    }
}
