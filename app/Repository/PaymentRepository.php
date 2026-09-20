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

    public function markSucceeded(Payment $payment, ?string $paymentMethodType = null, ?string $paymentInstrument = null): Payment
    {
        $payment->update(array_filter([
            'status'               => 'succeeded',
            'payment_method_type'  => $paymentMethodType,
            'payment_instrument'   => $paymentInstrument,
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

    public function getSubscriptionPaymentsByUserId(int $userId, int $perPage = 15)
    {
        return Payment::where('user_id', $userId)
            ->where('payable_type', \App\Models\Subscription::class)
            ->with(['payable.plan'])
            ->latest('created_at')
            ->paginate($perPage);
    }

    public function getAllSubscriptionPayments(int $perPage = 50)
    {
        return Payment::with(['user', 'payable.plan'])
            ->where('payable_type', \App\Models\Subscription::class)
            ->latest('created_at')
            ->paginate($perPage);
    }
}