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
            'cafe_picture'     => $this->cafe_picture ? url("/api/storage/public/{$this->cafe_picture}") : null, // public disk — served via Laravel route for CORS/headers
            'cafe_email'       => $this->cafe_email,
            'cafe_phonenumber' => $this->cafe_phonenumber,
            'address'          => $this->address,
            'branch_type'      => $this->branch_type,
            'status'           => $this->status,
            'documents'        => $this->whenLoaded('documents', fn () =>
                $this->documents->map(fn ($doc) => [
                    'branch_doc_id' => $doc->branch_doc_id,
                    'doc_type'      => $doc->doc_type,
                    'download_url'  => "/api/documents/branch/{$doc->branch_doc_id}",
                    'registered_at' => $doc->registered_at?->toISOString(),
                    'expired_at'    => $doc->expired_at?->toISOString(),
                ])
            ),
            'created_at'       => $this->created_at?->toISOString(),
        ];
    }
}