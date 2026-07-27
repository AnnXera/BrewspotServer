<?php

namespace App\Contracts;

interface PaymentAdapterInterface
{
    public function createCheckoutSession(array $payload): array;

    public function verifyWebhookSignature(string $rawPayload, string $signatureHeader, string $webhookSecret): bool;

    public function createCustomer(array $payload): array;

    public function createPlan(array $payload): array;

    public function createSubscription(string $customerId, string $planId): array;

    public function createCardPaymentMethod(array $card, array $billing): array;

    public function attachPaymentMethodToIntent(string $paymentIntentId, string $paymentMethodId): array;
}