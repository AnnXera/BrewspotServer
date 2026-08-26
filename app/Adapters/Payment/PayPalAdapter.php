<?php

namespace App\Adapters\Payment;

use App\Contracts\PaymentAdapterInterface;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

class PayPalAdapter implements PaymentAdapterInterface
{
    private string $baseUrl;

    public function __construct(
        private readonly string $clientId,
        private readonly string $clientSecret,
        private readonly string $mode = 'sandbox',
        private readonly ?string $webhookId = null
    ) {
        $this->baseUrl = $this->mode === 'live'
            ? 'https://api-m.paypal.com'
            : 'https://api-m.sandbox.paypal.com';
    }

    /**
     * PayPal uses OAuth2 client_credentials. Token cached for its lifetime
     * minus a safety margin so we're not requesting a new one every call.
     */
    private function getAccessToken(): string
    {
        return Cache::remember('paypal_access_token', 55 * 60, function () {
            $request = Http::asForm()
                ->withBasicAuth($this->clientId, $this->clientSecret);

            if ($this->mode === 'sandbox' || app()->isLocal()) {
                $request = $request->withoutVerifying();
            }

            $response = $request->post("{$this->baseUrl}/v1/oauth2/token", [
                'grant_type' => 'client_credentials',
            ]);

            if ($response->failed()) {
                throw new \RuntimeException('PayPal OAuth token request failed: ' . $response->body());
            }

            return $response->json('access_token');
        });
    }

    private function client(): PendingRequest
    {
        $request = Http::withToken($this->getAccessToken())->acceptJson();

        if ($this->mode === 'sandbox' || app()->isLocal()) {
            $request = $request->withoutVerifying();
        }

        return $request;
    }

    public function createCheckoutSession(array $payload): array
    {
        $response = $this->client()->post("{$this->baseUrl}/v2/checkout/orders", [
            'intent' => 'CAPTURE',
            'purchase_units' => [[
                'description' => $payload['description'],
                'amount' => [
                    'currency_code' => 'PHP',
                    'value'          => number_format($payload['amount'] / 100, 2, '.', ''),
                ],
                'custom_id' => $payload['metadata']['subscription_uuid'] ?? null,
            ]],
            'application_context' => [
                'return_url'  => $payload['success_url'],
                'cancel_url'  => $payload['cancel_url'],
                'user_action' => 'PAY_NOW',
            ],
        ]);

        if ($response->failed()) {
            throw new \RuntimeException('PayPal order creation failed: ' . $response->body());
        }

        $data = $response->json();

        return [
            'id'           => $data['id'],
            'checkout_url' => collect($data['links'])->firstWhere('rel', 'approve')['href'] ?? null,
        ];
    }

    public function captureOrder(string $orderId): array
    {
        $response = $this->client()
            ->withBody('{}', 'application/json')
            ->post("{$this->baseUrl}/v2/checkout/orders/{$orderId}/capture");

        if ($response->failed()) {
            throw new \RuntimeException('PayPal order capture failed: ' . $response->body());
        }

        return $response->json();
    }

    /**
     * PayPal requires a Product before a Plan can be created.
     */
    public function createPlan(array $payload): array
    {
        $product = $this->client()->post("{$this->baseUrl}/v1/catalogs/products", [
            'name'        => $payload['name'],
            'description' => $payload['description'] ?? $payload['name'],
            'type'        => 'SERVICE',
            'category'    => 'SOFTWARE',
        ]);

        if ($product->failed()) {
            throw new \RuntimeException('PayPal product creation failed: ' . $product->body());
        }

        $plan = $this->client()->post("{$this->baseUrl}/v1/billing/plans", [
            'product_id' => $product->json('id'),
            'name'       => $payload['name'],
            'billing_cycles' => [[
                'frequency' => [
                    'interval_unit'  => 'MONTH',
                    'interval_count' => 1,
                ],
                'tenure_type'   => 'REGULAR',
                'sequence'      => 1,
                'total_cycles'  => 0, // 0 = infinite
                'pricing_scheme' => [
                    'fixed_price' => [
                        'value'         => (string) ($payload['amount'] / 100),
                        'currency_code' => 'PHP',
                    ],
                ],
            ]],
            'payment_preferences' => [
                'auto_bill_outstanding'     => true,
                'payment_failure_threshold' => 3,
            ],
        ]);

        if ($plan->failed()) {
            throw new \RuntimeException('PayPal plan creation failed: ' . $plan->body());
        }

        return $plan->json();
    }

    public function createSubscription(string $planId, array $payload = []): array
    {
        $response = $this->client()->post("{$this->baseUrl}/v1/billing/subscriptions", [
            'plan_id' => $planId,
            'application_context' => [
                'return_url' => config('services.paypal.success_url'),
                'cancel_url' => config('services.paypal.cancel_url'),
            ],
        ]);

        if ($response->failed()) {
            throw new \RuntimeException('PayPal subscription creation failed: ' . $response->body());
        }

        return $response->json();
    }

    public function verifyWebhookSignature(string $rawPayload, string $signatureHeader, string $webhookId): bool
    {
        // $signatureHeader is a JSON-encoded bundle of the paypal-* headers
        // (see PaymentWebhookController) — PayPal needs several of them at once.
        $headers = json_decode($signatureHeader, true) ?? [];

        $response = $this->client()->post("{$this->baseUrl}/v1/notifications/verify-webhook-signature", [
            'transmission_id'   => $headers['paypal-transmission-id'] ?? null,
            'transmission_time' => $headers['paypal-transmission-time'] ?? null,
            'cert_url'          => $headers['paypal-cert-url'] ?? null,
            'auth_algo'         => $headers['paypal-auth-algo'] ?? null,
            'transmission_sig'  => $headers['paypal-transmission-sig'] ?? null,
            'webhook_id'        => $webhookId,
            'webhook_event'     => json_decode($rawPayload, true),
        ]);

        return $response->successful() && $response->json('verification_status') === 'SUCCESS';
    }
}