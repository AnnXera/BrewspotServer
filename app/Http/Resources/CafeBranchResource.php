<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Storage;

class CafeBranchResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'uuid'             => $this->uuid,
            'branch_name'      => $this->branch_name,
            'cafe_picture'     => $this->cafe_picture, // public disk — safe to expose directly
            'cafe_picture_url' => $this->cafe_picture
                ? Storage::disk('public')->url($this->cafe_picture)
                : null,
            'cafe_name'        => $this->whenLoaded('cafe', fn () => $this->cafe?->cafe_name),
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
                    'tin_number'    => $doc->tin_number,
                    'vat'           => $doc->vat,
                    'uploaded_at'   => $doc->created_at?->toISOString(),
                ])
            ),
            'created_at'       => $this->created_at?->toISOString(),
        ];
    }
}