<?php

namespace App\Providers;

use App\Services\Payments\FlutterwaveGateway;
use App\Services\Payments\PaystackGateway;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->bind(PaystackGateway::class, fn () => new PaystackGateway(
            config('services.paystack.secret_key'),
        ));

        $this->app->bind(FlutterwaveGateway::class, fn () => new FlutterwaveGateway(
            config('services.flutterwave.secret_key'),
            config('services.flutterwave.secret_hash'),
        ));
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        //
    }
}
