<?php

namespace App\Http\Requests;

/**
 * PATCH /api/{owner|manager}/branches/{branchUuid}/floor-plans/{planUuid}
 */
class UpdateFloorPlanRequest extends StoreFloorPlanRequest
{
    public function rules(): array
    {
        return [
            'floorplan_name' => ['sometimes', 'string', 'max:100', $this->uniqueNameRule($this->route('planUuid'))],
            ...self::canvasRules(false),
        ];
    }
}
