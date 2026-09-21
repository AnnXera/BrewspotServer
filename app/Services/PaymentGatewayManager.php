<?php

namespace App\Services;

use App\Contracts\PaymentAdapterInterface;
use App\Adapters\Payment\PayMongoAdapter;
use InvalidArgumentException;

class PaymentGatewayManager
{
    /**
     * BrewSpot bills through PayMongo only. The manager is kept rather than inlining the
     * adapter so a second gateway stays a one-line addition, and so callers keep resolving
     * their adapter through a single place.
     */
    public function gateway(?string $name = 'paymongo'): PaymentAdapterInterface
    {
        return match (strtolower($name ?? 'paymongo')) {
            'paymongo' => new PayMongoAdapter(
                config('services.paymongo.public_key'),
                config('services.paymongo.secret_key'),
                config('services.paymongo.webhook_secret')
            ),
            default => throw new InvalidArgumentException("Unsupported payment gateway: {$name}"),
        };
    }
}
