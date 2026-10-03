<?php

namespace App\Providers;

use App\Mail\Transport\BrevoTransport;
use App\Services\Payments\FlutterwaveGateway;
use App\Services\Payments\PaystackGateway;
use App\Services\Retention\DeliveryTracker;
use App\Settings\SettingsStore;
use App\Support\PageMeta;
use Illuminate\Notifications\Events\NotificationSent;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Mail;
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

        // One set of link-preview tags per request (item 48).
        $this->app->scoped(PageMeta::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Admin-edited settings (Settings page) on top of .env — except
        // while `config:cache` / `optimize` snapshot the config to a file:
        // that snapshot must hold only .env values, never database values
        // or decrypted secrets.
        if (! $this->isCachingConfig()) {
            SettingsStore::apply();
        }

        Mail::extend('brevo', fn () => new BrevoTransport);

        // Message tracking (App\Services\Retention\DeliveryTracker): an email
        // or push left us, or gave up after its retries.
        Event::listen(NotificationSent::class, fn (NotificationSent $e) => rescue(
            fn () => app(DeliveryTracker::class)->notificationSent($e->notifiable, $e->notification, $e->channel), report: true,
        ));
        Event::listen(JobFailed::class, fn (JobFailed $e) => app(DeliveryTracker::class)->jobFailed($e->job, $e->exception));
    }

    private function isCachingConfig(): bool
    {
        return $this->app->runningInConsole()
            && in_array($_SERVER['argv'][1] ?? null, ['config:cache', 'optimize'], true);
    }
}
