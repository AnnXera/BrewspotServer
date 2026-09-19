<?php

namespace App\Contracts;

interface PaymentAdapterInterface
{
    /**
     * One-time checkout — creates a PayPal Order and returns the buyer
     * approval link.
     */
    public function createCheckoutSession(array $payload): array;

    /**
     * Captures funds for an approved Order. Called after the buyer approves
     * on PayPal's site, typically triggered by the CHECKOUT.ORDER.APPROVED
     * webhook.
     */
    public function captureOrder(string $orderId): array;

    /**
     * Creates a PayPal billing Product + Plan for recurring subscriptions.
     */
    public function createPlan(array $payload): array;

    /**
     * Creates a PayPal billing Subscription against an existing plan.
     */
    public function createSubscription(string $planId, array $payload = []): array;

    /**
     * Verifies a webhook via PayPal's server-to-server verification call.
     */
    public function verifyWebhookSignature(string $rawPayload, string $signatureHeader, string $webhookId): bool;
}