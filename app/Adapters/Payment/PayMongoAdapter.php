<?php

namespace App\Adapters\Payment;

use App\Contracts\PaymentAdapterInterface;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

class PayMongoAdapter implements PaymentAdapterInterface
{
    private string $baseUrl = 'https://api.paymongo.com';

    public function __construct(
        private readonly string $publicKey,
        private readonly string $secretKey,
        private readonly string $webhookSecret
    ) {
    }

    private function client(): PendingRequest
    {
        return Http::withBasicAuth($this->secretKey, '')
            ->acceptJson();
    }

    public function createCheckoutSession(array $payload): array
    {
        // PayMongo Checkout Session
        $response = $this->client()->post("{$this->baseUrl}/v1/checkout_sessions", [
            'data' => [
                'attributes' => [
                    'billing' => [
                        'name' => $payload['metadata']['owner_name'] ?? 'Cafe Owner',
                        'email' => $payload['metadata']['owner_email'] ?? 'test@example.com',
                    ],
                    'send_email_receipt' => true,
                    'show_description' => true,
                    'show_line_items' => true,
                    'cancel_url' => $payload['cancel_url'],
                    'success_url' => $payload['success_url'],
                    'line_items' => [[
                        'currency' => 'PHP',
                        'amount' => (int) $payload['amount'], // PayMongo expects integer (cents)
                        'description' => $payload['description'] ?? 'Payment',
                        'name' => $payload['description'] ?? 'Item',
                        'quantity' => 1,
                    ]],
                    'payment_method_types' => ['card', 'paymaya', 'gcash', 'grab_pay'],
                    'metadata' => $payload['metadata'] ?? [],
                ]
            ]
        ]);

        if ($response->failed()) {
            throw new \RuntimeException('PayMongo checkout session creation failed: ' . $response->body());
        }

        $data = $response->json('data');

        return [
            'id' => $data['id'],
            'checkout_url' => $data['attributes']['checkout_url'],
        ];
    }

    public function captureOrder(string $orderId): array
    {
        // For checkout sessions, we might just retrieve it to verify
        $response = $this->client()->get("{$this->baseUrl}/v1/checkout_sessions/{$orderId}");
        
        if ($response->failed()) {
            throw new \RuntimeException('PayMongo retrieve session failed: ' . $response->body());
        }

        return $response->json('data');
    }

    public function createPlan(array $payload): array
    {
        // PayMongo Plans API (assuming standard spec)
        $billingCycle = $payload['billing_cycle'] ?? 'monthly';
        $interval = $billingCycle === 'yearly' ? 'year' : 'month';

        $response = $this->client()->post("{$this->baseUrl}/v1/plans", [
            'data' => [
                'attributes' => [
                    'amount' => (int) $payload['amount'],
                    'currency' => 'PHP',
                    'interval' => $interval,
                    'name' => $payload['name'],
                ]
            ]
        ]);

        if ($response->failed()) {
            throw new \RuntimeException('PayMongo plan creation failed: ' . $response->body());
        }

        return $response->json('data');
    }

    public function createSubscription(string $planId, array $payload = []): array
    {
        // Let's create a customer first
        $customerResponse = $this->client()->post("{$this->baseUrl}/v1/customers", [
            'data' => [
                'attributes' => [
                    'default_device' => 'email',
                    'first_name' => $payload['metadata']['first_name'] ?? 'User',
                    'last_name' => $payload['metadata']['last_name'] ?? 'User',
                    'email' => $payload['metadata']['email'] ?? 'user@example.com',
                ]
            ]
        ]);

        if ($customerResponse->failed()) {
            throw new \RuntimeException('PayMongo customer creation failed: ' . $customerResponse->body());
        }

        $customerId = $customerResponse->json('data.id');

        // Now create the subscription
        $subscriptionResponse = $this->client()->post("{$this->baseUrl}/v1/subscriptions", [
            'data' => [
                'attributes' => [
                    'customer_id' => $customerId,
                    'plan_id' => $planId,
                    'metadata' => $payload['metadata'] ?? [],
                ]
            ]
        ]);

        if ($subscriptionResponse->failed()) {
            throw new \RuntimeException('PayMongo subscription creation failed: ' . $subscriptionResponse->body());
        }
        
        $subData = $subscriptionResponse->json('data');

        return array_merge($subData, [
            // PayMongo subscription endpoint might return a payment intent or checkout url if it's the first charge.
            // But if it doesn't, we can handle the logic later.
            'checkout_url' => '', // Will need to be handled if PayMongo requires off-session checkout or invoice payment.
        ]);
    }

    public function verifyWebhookSignature(string $rawPayload, string $signatureHeader, ?string $webhookId = null): bool
    {
        // signatureHeader for PayMongo comes in the format: t=1600000000,te=test_sig,li=live_sig
        $parts = explode(',', $signatureHeader);
        $timestamp = '';
        $testSignature = '';
        $liveSignature = '';

        foreach ($parts as $part) {
            $keyValue = explode('=', $part, 2);
            if (count($keyValue) === 2) {
                if ($keyValue[0] === 't') $timestamp = $keyValue[1];
                if ($keyValue[0] === 'te') $testSignature = $keyValue[1];
                if ($keyValue[0] === 'li') $liveSignature = $keyValue[1];
            }
        }

        if (empty($timestamp)) {
            return false;
        }

        $signatureToCompare = Str::startsWith($this->secretKey, 'sk_test_') ? $testSignature : $liveSignature;
        $expectedSignature = hash_hmac('sha256', $timestamp . '.' . $rawPayload, $this->webhookSecret);

        return hash_equals($expectedSignature, $signatureToCompare);
    }
}
