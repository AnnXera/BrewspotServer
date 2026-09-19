<?php

namespace App\Http\Resources;

use App\Models\SubscriptionPlan;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class SubscriptionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'uuid'                  => $this->uuid,
            'status'                => $this->status,
            'billing_cycle'         => $this->billing_cycle,
            'pending_billing_cycle' => $this->pending_billing_cycle,
            'start_date'            => $this->start_date?->toISOString(),
            'end_date'              => $this->end_date?->toISOString(),
            'cancel_at_period_end'  => $this->cancel_at_period_end,
            'plan'                  => $this->whenLoaded('plan', fn () => $this->planToArray($this->plan)),
            'payment_gateway'       => $this->latestPayment?->payment_instrument
                ?? ($this->latestPayment?->payment_method_type ? 'PayPal' : null),
            'pending_plan'          => $this->whenLoaded('pendingPlan', fn () => $this->pendingPlan
                ? $this->planToArray($this->pendingPlan)
                : null),
            'paypal_subscription_id'=> $this->paypal_subscription_id,
            'created_at'            => $this->created_at?->toISOString(),
        ];
    }

    private function planToArray(SubscriptionPlan $plan): array
    {
        return [
            'uuid'             => $plan->uuid,
            'sub_name'         => $plan->sub_name,
            'price'            => $plan->price,
            'monthly_price'    => $plan->price,
            'yearly_price'     => $plan->yearly_price ?? 0.00,
            'has_multi_branch' => $plan->relationLoaded('features')
                ? $plan->features->contains('key', 'multi_branch')
                : $plan->hasFeature('multi_branch'),
            'duration_days'    => $plan->duration_days,
            'features'         => $plan->features instanceof \Illuminate\Support\Collection
                ? $plan->features->pluck('key')->values()->toArray()
                : (is_array($plan->features) ? $plan->features : []),
            'feature_details'  => $plan->features instanceof \Illuminate\Support\Collection
                ? FeatureResource::collection($plan->features)
                : [],
        ];
    }
}