<?php

namespace App\Services;

use App\Contracts\PaymentAdapterInterface;
use App\Adapters\Payment\PayPalAdapter;
use App\Adapters\Payment\PayMongoAdapter;
use InvalidArgumentException;

class PaymentGatewayManager
{
    public function gateway(?string $name = 'paypal'): PaymentAdapterInterface
    {
        return match (strtolower($name ?? 'paypal')) {
            'paypal' => new PayPalAdapter(
                config('services.paypal.client_id'),
                config('services.paypal.client_secret'),
                config('services.paypal.mode'),
                config('services.paypal.webhook_id')
            ),
            'paymongo' => new PayMongoAdapter(
                config('services.paymongo.public_key'),
                config('services.paymongo.secret_key'),
                config('services.paymongo.webhook_secret')
            ),
            default => throw new InvalidArgumentException("Unsupported payment gateway: {$name}"),
        };
    }
}
