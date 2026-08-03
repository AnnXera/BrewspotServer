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
            'cafe_picture' => $this->cafe_picture
                ? Storage::disk('public')->url($this->cafe_picture)
                : null,
            'status'       => $this->status,
        ];
    }
}