<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AdminSubscriberResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $payment = $this->whenLoaded('latestPayment');

        return [
            'subscription_uuid' => $this->uuid,
            'status'            => $this->status,
            'name'              => trim(($this->user->firstname ?? '') . ' ' . ($this->user->lastname ?? '')),
            'email'             => $this->user->email ?? null,
            'phone_number'      => $this->user->phone_number ?? null,
            'plan'              => $this->plan->sub_name ?? null,
            'mode_of_payment'   => $payment->payment_method_type ?? null,
            'amount'            => $payment ? number_format($payment->amount / 100, 2) : null,
        ];
    }
}