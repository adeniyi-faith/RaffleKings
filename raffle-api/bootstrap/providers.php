<?php

use App\Providers\AppServiceProvider;
use App\Providers\AuthRateLimiterServiceProvider;
use App\Providers\Filament\AdminPanelProvider;
use App\Providers\WordPressAuthServiceProvider;

return [
    AppServiceProvider::class,
    AdminPanelProvider::class,
    WordPressAuthServiceProvider::class,
    AuthRateLimiterServiceProvider::class,
];
