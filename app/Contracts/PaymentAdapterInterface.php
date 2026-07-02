<?php

namespace App\Contracts;

interface PaymentAdapterInterface
{
    /**
     * @return array{id: string, checkout_url: string}
     */
    public function createCheckoutSession(array $payload): array;

    public function verifyWebhookSignature(string $rawPayload, string $signatureHeader, string $webhookSecret): bool;
}