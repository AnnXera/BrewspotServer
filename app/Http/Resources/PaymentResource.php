<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PaymentResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $planName = null;
        $billingCycle = null;

        if ($this->payable) {
            // Because payable is MorphTo (Subscription)
            $planName = $this->payable->plan ? $this->payable->plan->sub_name : 'Subscription Plan';
            $billingCycle = $this->payable->billing_cycle;
        }

        $description = $planName;
        if ($billingCycle) {
            $cycleText = $billingCycle === 'yearly' ? 'Yearly' : 'Monthly';
            $description = "$cycleText Subscription - $planName";
        }

        return [
            'uuid' => $this->uuid,
            'transaction_id' => $this->gateway_transaction_id,
            'date' => $this->created_at->toISOString(),
            'description' => $description ?? 'Subscription Payment',
            'amount' => $this->amount,
            'status' => $this->status,
            'payment_gateway' => $this->payment_method_type ?? 'Unknown',
            'owner_name' => $this->user ? trim("{$this->user->firstname} {$this->user->lastname}") : null,
            'owner_email' => $this->user ? $this->user->email : null,
        ];
    }
}
