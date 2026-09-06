<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class SubscriptionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'uuid'                  => $this->uuid,
            'status'                => $this->status,
            'billing_cycle'         => $this->billing_cycle ?? 'monthly',
            'start_date'            => $this->start_date?->toISOString(),
            'end_date'              => $this->end_date?->toISOString(),
            'cancel_at_period_end'  => $this->cancel_at_period_end,
            'plan'                  => $this->whenLoaded('plan', fn () => [
                'uuid'          => $this->plan->uuid,
                'sub_name'      => $this->plan->sub_name,
                'price'         => $this->plan->price,
                'monthly_price' => $this->plan->price,
                'yearly_price'  => $this->plan->yearly_price ?? 0.00,
                'max_branches'  => $this->plan->max_branches,
                'duration_days' => $this->plan->duration_days,
                'features'      => $this->plan->features ?? [],
            ]),
            'created_at'            => $this->created_at?->toISOString(),
        ];
    }
}