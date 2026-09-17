<?php

namespace App\Providers;

use App\Auth\WordPressAuthCookieIssuer;
use App\Auth\WordPressAuthCookieValidator;
use App\Auth\WordPressOrSanctumGuard;
use App\Auth\WordPressSessionGuard;
use Illuminate\Cookie\Middleware\EncryptCookies;
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
        // Phase 3 item 34: the 'wordpress' guard now tries a Sanctum
        // token first and falls back to the original WordPress-cookie
        // check — see WordPressOrSanctumGuard's own docblock for why a
        // hard cutover isn't safe yet. Kept as the SAME driver name
        // ('wordpress_session') and guard name ('wordpress' in
        // config/auth.php) specifically so every existing
        // `auth:wordpress` route, `Auth::guard('wordpress')` call, and
        // Filament's `authGuard('wordpress')` keeps working with zero
        // changes anywhere else — this is purely additive.
        Auth::extend('wordpress_session', function () {
            return new WordPressOrSanctumGuard(
                new WordPressSessionGuard(
                    new WordPressAuthCookieValidator(
                        config('legacy.wp_logged_in_key'),
                        config('legacy.wp_logged_in_salt'),
                    ),
                    $this->app->make('wordpress.auth_cookie_name'),
                ),
            );
        });

        // Real bug found while building item 26: bootstrap/app.php already
        // excepted this cookie from Laravel's cookie encryption (item 19's
        // fix for Filament), but it computed the cookie name with a bare
        // `env('WP_COOKIEHASH', '')` call inside the `withMiddleware()`
        // closure — which `Application::configure()->create()` runs
        // immediately, BEFORE the `LoadEnvironmentVariables` bootstrapper
        // has actually parsed `.env` for that request. On a real server
        // where WP_COOKIEHASH etc. happen to already be set as literal OS
        // environment variables (e.g. exported by the hosting panel, or
        // baked into `phpunit.xml` for tests) that race is invisible; on
        // a completely ordinary setup that only has a `.env` file — the
        // exact setup LEGACY_MIGRATION.md tells you to use — `env()`
        // resolves to `''` at that point, so the except list only ever
        // contains the cookie name's static prefix with no hash, the real
        // cookie gets treated as an (invalid) encrypted cookie, decryption
        // fails, and `$request->cookies` silently gets `null` for it —
        // meaning EVERY page behind the `wordpress` guard, including this
        // entire account section, would show a guest to an actually
        // logged-in user. A service provider's `boot()` runs after
        // environment/config are fully loaded, so registering the
        // exception here (in addition to, not instead of, the
        // bootstrap/app.php one, which stays harmless) guarantees the
        // correctly-named cookie is always excepted by the time any
        // 'web'-routed request is decrypted.
        //
        // Deliberately reads `config('legacy.wp_cookiehash')` directly
        // here rather than resolving the `wordpress.auth_cookie_name`
        // singleton above: that singleton is resolved lazily, the first
        // time something actually needs it (e.g. LoginController setting
        // the cookie on a real response), and several tests rely on being
        // able to override `legacy.wp_cookiehash` in their own setUp()
        // AFTER the application has booted but BEFORE that first
        // resolution. Forcing it to resolve here, during boot(), would
        // permanently cache the pre-override value in that singleton for
        // the rest of the test — a real regression this caused the first
        // time this fix was written, caught by the existing
        // LoginControllerTest/RegisterControllerTest suite.
        EncryptCookies::except(['wordpress_logged_in_'.config('legacy.wp_cookiehash')]);
    }
}
