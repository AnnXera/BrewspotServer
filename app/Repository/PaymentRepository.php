<?php

namespace App\Repository;

use App\Models\Payment;

class PaymentRepository
{
    public function create(array $data): Payment
    {
        return Payment::create($data);
    }

    public function findByGatewayTransactionId(string $gatewayTransactionId): ?Payment
    {
        return Payment::where('gateway_transaction_id', $gatewayTransactionId)->first();
    }

    public function markSucceeded(Payment $payment, ?string $paymentMethodType = null): Payment
    {
        $payment->update(array_filter([
            'status'               => 'succeeded',
            'payment_method_type'  => $paymentMethodType,
        ], fn ($value) => $value !== null));

        return $payment->fresh();
    }

    public function markFailed(Payment $payment, ?string $paymentMethodType = null): Payment
    {
        $payment->update(array_filter([
            'status'               => 'failed',
            'payment_method_type'  => $paymentMethodType,
        ], fn ($value) => $value !== null));

        return $payment->fresh();
    }
}