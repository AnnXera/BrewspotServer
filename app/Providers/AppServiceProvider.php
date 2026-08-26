<?php

namespace App\Providers;

use App\Adapters\Payment\PayPalAdapter;
use App\Adapters\Mail\LaravelMailAdapter;
use App\Contracts\PaymentAdapterInterface;
use App\Contracts\MailAdapterInterface;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(MailAdapterInterface::class, LaravelMailAdapter::class);

        $this->app->bind(PaymentAdapterInterface::class, function ($app) {
            return new PayPalAdapter(
                config('services.paypal.client_id'),
                config('services.paypal.client_secret'),
                config('services.paypal.mode'),
                config('services.paypal.webhook_id'),
            );
        });
    }

    public function boot(): void
    {
        //
    }
}