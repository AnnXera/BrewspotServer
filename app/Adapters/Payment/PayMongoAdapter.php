<?php

namespace App\Adapters\Payment;

use App\Contracts\PaymentAdapterInterface;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;

class PayMongoAdapter implements PaymentAdapterInterface
{
    public function __construct(
        private readonly string $secretKey,
        private readonly ?string $caBundlePath = null
    ) {}

    private function client(): PendingRequest
    {
        $request = Http::withBasicAuth($this->secretKey, '')->acceptJson();

        if ($this->caBundlePath) {
            $request = $request->withOptions(['verify' => $this->caBundlePath]);
        }

        return $request;
    }

    public function createCheckoutSession(array $payload): array
    {
        $response = $this->client()->post('https://api.paymongo.com/v1/checkout_sessions', [
            'data' => [
                'attributes' => [
                    'send_email_receipt'  => false,
                    'show_description'    => true,
                    'show_line_items'     => true,
                    'description'         => $payload['description'],
                    'line_items'           => [
                        [
                            'currency' => 'PHP',
                            'amount'   => $payload['amount'],
                            'name'     => $payload['plan_name'],
                            'quantity' => 1,
                        ],
                    ],
                    'payment_method_types' => ['card', 'gcash', 'paymaya'],
                    'success_url'          => $payload['success_url'],
                    'cancel_url'           => $payload['cancel_url'],
                    'metadata'             => $payload['metadata'],
                ],
            ],
        ]);

        if ($response->failed()) {
            throw new \RuntimeException('PayMongo checkout session creation failed: ' . $response->body());
        }

        $data = $response->json('data');

        return [
            'id'                => $data['id'],
            'checkout_url'      => $data['attributes']['checkout_url'],
            'payment_intent_id' => $data['attributes']['payment_intent']['id'] ?? null,
        ];
    }

    public function verifyWebhookSignature(string $rawPayload, string $signatureHeader, string $webhookSecret): bool
    {
        $parts = [];

        foreach (explode(',', $signatureHeader) as $segment) {
            [$key, $value] = array_pad(explode('=', $segment, 2), 2, null);
            $parts[$key] = $value;
        }

        $timestamp     = $parts['t'] ?? null;
        $testSignature = $parts['te'] ?? null;
        $liveSignature = $parts['li'] ?? null;

        if (! $timestamp) {
            return false;
        }

        $signedPayload     = $timestamp . '.' . $rawPayload;
        $computedSignature = hash_hmac('sha256', $signedPayload, $webhookSecret);
        $providedSignature = ! empty($liveSignature) ? $liveSignature : $testSignature;

        if (! $providedSignature) {
            return false;
        }

        return hash_equals($computedSignature, $providedSignature);
    }

    public function createCustomer(array $payload): array
    {
        $response = $this->client()->post('https://api.paymongo.com/v1/customers', [
            'data' => ['attributes' => $payload],
        ]);

        if ($response->failed()) {
            throw new \RuntimeException('PayMongo customer creation failed: ' . $response->body());
        }

        return $response->json('data');
    }

    public function createPlan(array $payload): array
    {
        $response = $this->client()->post('https://api.paymongo.com/v1/plans', [
            'data' => ['attributes' => $payload],
        ]);

        if ($response->failed()) {
            throw new \RuntimeException('PayMongo plan creation failed: ' . $response->body());
        }

        return $response->json('data');
    }

    public function createSubscription(string $customerId, string $planId): array
    {
        $response = $this->client()->post('https://api.paymongo.com/v1/subscriptions', [
            'data' => [
                'attributes' => [
                    'customer_id' => $customerId,
                    'plan_id'     => $planId,
                ],
            ],
        ]);

        if ($response->failed()) {
            throw new \RuntimeException('PayMongo subscription creation failed: ' . $response->body());
        }

        return $response->json('data');
    }

    public function createCardPaymentMethod(array $card, array $billing): array
    {
        $response = $this->client()->post('https://api.paymongo.com/v1/payment_methods', [
            'data' => [
                'attributes' => [
                    'type'    => 'card',
                    'details' => $card, // card_number, exp_month, exp_year, cvc
                    'billing' => $billing,
                ],
            ],
        ]);

        if ($response->failed()) {
            throw new \RuntimeException('PayMongo payment method creation failed: ' . $response->body());
        }

        return $response->json('data');
    }

    public function attachPaymentMethodToIntent(string $paymentIntentId, string $paymentMethodId): array
    {
        $response = $this->client()->post("https://api.paymongo.com/v1/payment_intents/{$paymentIntentId}/attach", [
            'data' => [
                'attributes' => [
                    'payment_method' => $paymentMethodId,
                    'return_url'     => config('services.paymongo.success_url'),
                ],
            ],
        ]);

        if ($response->failed()) {
            throw new \RuntimeException('PayMongo payment intent attach failed: ' . $response->body());
        }

        return $response->json('data');
    }
}