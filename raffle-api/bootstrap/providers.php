<?php

use App\Providers\AppServiceProvider;
use App\Providers\AuthRateLimiterServiceProvider;
use App\Providers\Filament\AdminPanelProvider;
use App\Providers\WordPressAuthServiceProvider;
use Sentry\Laravel\ServiceProvider as SentryServiceProvider;

return [
    AppServiceProvider::class,
    // Sentry is registered here on purpose, not auto-discovered (see composer.json):
    // it reads its DSN the moment it boots, and the DSN can be saved in the admin
    // (Settings → Alerts & push). AppServiceProvider applies admin settings first.
    SentryServiceProvider::class,
    AdminPanelProvider::class,
    WordPressAuthServiceProvider::class,
    AuthRateLimiterServiceProvider::class,
];
