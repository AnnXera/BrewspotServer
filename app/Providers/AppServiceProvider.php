<?php

namespace App\Providers;

use App\Contracts\MailAdapterInterface;
use App\Adapters\Mail\LaravelMailAdapter;
use Illuminate\Support\ServiceProvider;
use App\Contracts\PaymentAdapterInterface;
use App\Adapters\Payment\PayMongoAdapter;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(MailAdapterInterface::class, LaravelMailAdapter::class);
        $this->app->bind(PaymentAdapterInterface::class, function () {
            $caBundle = config('services.paymongo.ca_bundle');

            return new PayMongoAdapter(
                config('services.paymongo.secret_key'),
                $caBundle ? base_path($caBundle) : null
            );
        });
    }

    public function boot(): void
    {
        //
    }
}