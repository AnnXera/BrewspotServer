<?php

namespace App\Providers;

use App\Adapters\Mail\LaravelMailAdapter;
use App\Contracts\PaymentAdapterInterface;
use App\Contracts\MailAdapterInterface;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(MailAdapterInterface::class, LaravelMailAdapter::class);

        $this->app->singleton(\App\Services\PaymentGatewayManager::class, function ($app) {
            return new \App\Services\PaymentGatewayManager();
        });

        // Default binding for callers that type-hint the interface directly
        $this->app->bind(PaymentAdapterInterface::class, function ($app) {
            return $app->make(\App\Services\PaymentGatewayManager::class)->gateway('paymongo');
        });
    }

    public function boot(): void
    {
        //
    }
}