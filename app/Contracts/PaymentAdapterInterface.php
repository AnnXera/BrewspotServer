<?php

namespace App\Contracts;

interface PaymentAdapterInterface
{
    /**
     * One-time checkout — creates a hosted checkout session at the gateway and
     * returns its id plus the URL the owner is sent to in order to pay.
     */
    public function createCheckoutSession(array $payload): array;

    /**
     * Retrieves a checkout session / order from the gateway so its status can be
     * confirmed server-side rather than trusted from a browser redirect.
     */
    public function captureOrder(string $orderId): array;

    /**
     * Creates a recurring billing plan at the gateway.
     *
     * Only used where the gateway manages renewals itself. BrewSpot currently renews
     * through owner-initiated checkout, so this is unused until PayMongo enables
     * subscriptions on the account.
     */
    public function createPlan(array $payload): array;

    /**
     * Creates a gateway-managed subscription against an existing plan.
     *
     * @see createPlan() for when this applies.
     */
    public function createSubscription(string $planId, array $payload = []): array;

    /**
     * Verifies that a webhook payload genuinely came from the gateway.
     *
     * $webhookId is optional: gateways that sign payloads with a shared secret
     * (PayMongo) do not need it, while those that verify server-to-server do.
     */
    public function verifyWebhookSignature(string $rawPayload, string $signatureHeader, ?string $webhookId = null): bool;
}
