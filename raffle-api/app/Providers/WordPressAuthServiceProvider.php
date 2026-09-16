<?php

namespace App\Providers;

use App\Auth\WordPressAuthCookieIssuer;
use App\Auth\WordPressAuthCookieValidator;
use App\Auth\WordPressSessionGuard;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\ServiceProvider;

class WordPressAuthServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(WordPressAuthCookieIssuer::class, fn () => new WordPressAuthCookieIssuer(
            config('legacy.wp_logged_in_key'),
            config('legacy.wp_logged_in_salt'),
        ));

        $this->app->singleton('wordpress.auth_cookie_name', fn () => 'wordpress_logged_in_'.config('legacy.wp_cookiehash'));
    }

    public function boot(): void
    {
        Auth::extend('wordpress_session', function () {
            return new WordPressSessionGuard(
                new WordPressAuthCookieValidator(
                    config('legacy.wp_logged_in_key'),
                    config('legacy.wp_logged_in_salt'),
                ),
                $this->app->make('wordpress.auth_cookie_name'),
            );
        });
    }
}
