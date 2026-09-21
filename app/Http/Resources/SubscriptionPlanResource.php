<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class SubscriptionPlanResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'uuid'             => $this->uuid,
            'sub_name'         => $this->sub_name,
            'price'            => $this->price,
            'monthly_price'    => $this->price,
            'yearly_price'     => $this->yearly_price ?? 0.00,
            'gateway_plan_id'        => $this->gateway_plan_id,
            'gateway_yearly_plan_id' => $this->gateway_yearly_plan_id,
            'has_multi_branch' => $this->relationLoaded('features')
                ? $this->features->contains('key', 'multi_branch')
                : $this->hasFeature('multi_branch'),
            'features'      => $this->features instanceof \Illuminate\Support\Collection
                ? $this->features->pluck('key')->values()->toArray()
                : (is_array($this->features) ? $this->features : []),
            'feature_details' => $this->features instanceof \Illuminate\Support\Collection
                ? FeatureResource::collection($this->features)
                : [],
            'description'   => $this->description,
            'duration_days' => $this->duration_days,
            'is_active'     => $this->is_active,
            'created_at'    => $this->created_at?->toISOString(),
            'updated_at'    => $this->updated_at?->toISOString(),
        ];
    }
}