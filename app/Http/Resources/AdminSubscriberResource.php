<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AdminSubscriberResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $payment = $this->whenLoaded('latestPayment');

        $rawId = $payment?->uuid ?? $this->uuid;
        $cleanId = strtoupper(substr(str_replace('-', '', $rawId), 0, 7));

        return [
            'subscription_uuid' => $this->uuid,
            'transaction_id'    => "TXN-{$cleanId}",
            'status'            => $payment?->status ?? $this->status,
            'billing_cycle'     => $this->billing_cycle,
            'name'              => trim(($this->user->firstname ?? '') . ' ' . ($this->user->lastname ?? '')),
            'email'             => $this->user->email ?? null,
            'phone_number'      => $this->user->phone_number ?? null,
            'plan'              => $this->plan->sub_name ?? null,
            'mode_of_payment'   => $payment?->payment_instrument ?? 'PayMongo',
            'amount'            => $payment ? number_format($payment->amount / 100, 2) : number_format($this->plan->price ?? 0, 2),
            'date'              => ($payment?->created_at ?? $this->created_at)?->toISOString(),
        ];
    }
}