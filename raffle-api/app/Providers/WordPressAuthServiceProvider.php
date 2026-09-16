<?php

namespace App\Providers;

use App\Auth\WordPressAuthCookieValidator;
use App\Auth\WordPressSessionGuard;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\ServiceProvider;

class WordPressAuthServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        Auth::extend('wordpress_session', function ($app) {
            $cookieName = 'wordpress_logged_in_'.config('legacy.wp_cookiehash');

            return new WordPressSessionGuard(
                new WordPressAuthCookieValidator(
                    config('legacy.wp_logged_in_key'),
                    config('legacy.wp_logged_in_salt'),
                ),
                $app['request'],
                $cookieName,
            );
        });
    }
}
