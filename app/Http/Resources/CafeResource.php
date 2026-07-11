<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CafeResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'uuid'       => $this->uuid,
            'cafe_name'  => $this->cafe_name,
            'documents'  => $this->whenLoaded('documents', fn () =>
                $this->documents->map(fn ($doc) => [
                    'cafe_doc_id'   => $doc->cafe_doc_id,
                    'doc_type'      => $doc->doc_type,
                    'download_url'  => "/api/documents/cafe/{$doc->cafe_doc_id}",
                    'registered_at' => $doc->registered_at?->toISOString(),
                    'expired_at'    => $doc->expired_at?->toISOString(),
                ])
            ),
            'branches'   => CafeBranchResource::collection($this->whenLoaded('branches')),
            'created_at' => $this->created_at?->toISOString(),
        ];
    }
}