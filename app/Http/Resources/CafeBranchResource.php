<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CafeBranchResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'uuid'             => $this->uuid,
            'branch_name'      => $this->branch_name,
            'cafe_picture'     => $this->cafe_picture,
            'cafe_email'       => $this->cafe_email,
            'cafe_phonenumber' => $this->cafe_phonenumber,
            'address'          => $this->address,
            'branch_type'      => $this->branch_type,
            'status'           => $this->status,
            'documents'        => $this->whenLoaded('documents', fn () =>
                $this->documents->map(fn ($doc) => [
                    'doc_type'      => $doc->doc_type,
                    'file'          => $doc->file,
                    'registered_at' => $doc->registered_at?->toISOString(),
                    'expired_at'    => $doc->expired_at?->toISOString(),
                ])
            ),
            'created_at'       => $this->created_at?->toISOString(),
        ];
    }
}