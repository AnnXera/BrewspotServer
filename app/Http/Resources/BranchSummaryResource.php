<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Storage;

class BranchSummaryResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'uuid'         => $this->uuid,
            'branch_name'  => $this->branch_name,
            'branch_type'  => $this->branch_type,
            'address'      => $this->address,
            'cafe_picture' => $this->cafe_picture
                ? Storage::disk('public')->url($this->cafe_picture)
                : null,
            'status'       => $this->status,
            // Active managers, loaded by the list query. Null when none is assigned.
            'manager_name' => $this->whenLoaded('staff', fn () => $this->staff
                ->map(fn ($s) => trim("{$s->user?->firstname} {$s->user?->lastname}"))
                ->filter()
                ->join(', ') ?: null),
        ];
    }
}