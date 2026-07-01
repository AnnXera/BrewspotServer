<?php

namespace App\Providers;

use App\Contracts\MailAdapterInterface;
use App\Adapters\Mail\LaravelMailAdapter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(MailAdapterInterface::class, LaravelMailAdapter::class);
    }

    public function boot(): void
    {
        //
    }
}