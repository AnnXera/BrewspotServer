<?php

namespace App\Providers;

use App\Adapters\Mail\LaravelMailAdapter;
use App\Contracts\PaymentAdapterInterface;
use App\Contracts\MailAdapterInterface;
use App\Services\PaymentGatewayManager;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(MailAdapterInterface::class, LaravelMailAdapter::class);

        $this->app->singleton(PaymentGatewayManager::class);

        // Default binding for callers that type-hint the interface directly
        $this->app->bind(PaymentAdapterInterface::class, fn ($app) =>
            $app->make(PaymentGatewayManager::class)->gateway('paymongo')
        );
    }

    public function boot(): void
    {
        // PIN attempts on a registered register. Per-person lockout lives in
        // StaffPinService; this caps how fast any one device can try.
        RateLimiter::for('pos-unlock', fn (Request $request) =>
            Limit::perMinute(10)->by('pos-device:' . $request->user()?->getKey() . '|' . $request->ip())
        );
    }
}