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

    public function markSucceeded(Payment $payment): Payment
    {
        $payment->update(['status' => 'succeeded']);

        return $payment->fresh();
    }

    public function markFailed(Payment $payment): Payment
    {
        $payment->update(['status' => 'failed']);

        return $payment->fresh();
    }
}