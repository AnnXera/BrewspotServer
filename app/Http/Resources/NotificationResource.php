<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class NotificationResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $data = $this->data ?? [];

        return [
            'id'            => $this->id,
            'kind'          => $data['kind'] ?? null,
            'title'         => $data['title'] ?? 'Notification',
            'body'          => $data['body'] ?? null,
            'approval_uuid' => $data['approval_uuid'] ?? null,
            'link'          => $data['link'] ?? null,
            'read'          => $this->read_at !== null,
            'created_at'    => $this->created_at?->toIso8601String(),
        ];
    }
}
