<?php

namespace App\Providers\Filament;

use App\Filament\Pages\Auth\AdminLogin;
use App\Filament\Support\AdminSearch;
use App\Http\Middleware\AttachExtraCookies;
use App\Http\Middleware\RequireStaffTwoStep;
use App\Livewire\AdminBell;
use App\Services\Maintenance;
use App\Support\AdminPath;
use App\Support\Formats;
use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Infolists\Components\TextEntry;
use Filament\Pages;
use Filament\Panel;
use Filament\PanelProvider;
use Filament\Support\Colors\Color;
use Filament\Support\Facades\FilamentAsset;
use Filament\Tables\Columns\Summarizers;
use Filament\Tables\Columns\TextColumn;
use Filament\View\PanelsRenderHook;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Support\Facades\Blade;
use Illuminate\View\Middleware\ShareErrorsFromSession;
use Livewire\Livewire;

/**
 * Staff sign in at /admin/login (App\Filament\Pages\Auth\AdminLogin). It
 * checks the same WordPress account and password as the customer site and
 * sets the same login cookie (App\Auth\WordPressSessionGuard), so there is
 * still only one account and one password per person; only staff roles
 * (App\Auth\StaffRoles) get in. The cookie is excepted from EncryptCookies
 * globally in bootstrap/app.php: WordPress sets it, so it was never
 * Laravel-encrypted. Sign-out is WordPressOrSanctumGuard::logout().
 *
 * No AuthenticateSession middleware either — it calls a StatefulGuard
 * method (viaRemember()) our WordPressSessionGuard deliberately doesn't
 * implement, since there is no Laravel session login/logout to protect
 * against fixation on; the guard re-validates the WordPress cookie on
 * every request instead.
 */
class AdminPanelProvider extends PanelProvider
{
    public function boot(): void
    {
        // ->naira() / ->wholeNumber() instead of Filament's ->money() /
        // ->numeric(): those need PHP's "intl" extension and crash the page
        // on hosts without it (App\Support\Formats).
        foreach ([TextColumn::class, TextEntry::class] as $component) {
            $component::macro('naira', fn () => $this->formatStateUsing(fn ($state) => Formats::naira($state)));
            $component::macro('wholeNumber', fn () => $this->formatStateUsing(fn ($state) => Formats::wholeNumber($state)));
        }

        // Table totals (Sum/Average/Count under a column) switch on
        // ->numeric() by themselves, which needs intl too. "Important" so it
        // runs after Filament's own setup; a page's own ->formatStateUsing()
        // still wins.
        foreach ([Summarizers\Sum::class, Summarizers\Average::class, Summarizers\Count::class] as $summarizer) {
            $summarizer::configureUsing(fn ($s) => $s->formatStateUsing(fn ($state) => Formats::wholeNumber($state)), isImportant: true);
        }

        // Adds ?v=<version> to the admin's CSS/JS links, so after a deploy
        // browsers and Cloudflare fetch the new files instead of an old
        // cached copy. Set ASSET_VERSION in .env (the deploy uses the commit id).
        FilamentAsset::appVersion(config('app.asset_version'));

        Livewire::component('admin-bell', AdminBell::class);
    }

    public function panel(Panel $panel): Panel
    {
        return $panel
            ->default()
            ->id('admin')
            ->path(AdminPath::segment())
            ->authGuard('wordpress')
            // The admin's own sign-in page: same accounts and password check
            // as the site, staff only (App\Filament\Pages\Auth\AdminLogin).
            ->login(AdminLogin::class)
            ->brandName('RaffleKings')
            ->colors([
                'primary' => Color::Amber,
            ])
            // Pages swap in place instead of reloading the whole admin —
            // noticeably quicker on a phone connection.
            ->spa()
            ->sidebarCollapsibleOnDesktop()
            ->sidebarWidth('17rem')
            // The top search box finds actions, screens, settings and records
            // (App\Filament\Support\AdminSearch), not only customers.
            ->globalSearch(AdminSearch::class)
            ->globalSearchKeyBindings(['command+k', 'ctrl+k'])
            // Phone layout: styles + "menu starts closed", and the bottom
            // tab bar (resources/views/filament/hooks).
            ->renderHook(PanelsRenderHook::HEAD_END, fn () => view('filament.hooks.head'))
            // The bell next to your picture: the team to-do list, ticked off
            // together (App\Livewire\AdminBell, App\Services\Admin\StaffTodo).
            ->renderHook(PanelsRenderHook::USER_MENU_BEFORE, fn () => auth('wordpress')->user()?->staffRole() ? Blade::render('@livewire(\'admin-bell\')') : '')
            ->renderHook(PanelsRenderHook::BODY_END, fn () => auth('wordpress')->user()?->staffRole() ? view('filament.hooks.bottom-nav') : '')
            // A red reminder on every admin page while maintenance mode is on.
            ->renderHook(PanelsRenderHook::CONTENT_START, fn () => app(Maintenance::class)->active() ? view('filament.hooks.maintenance-banner') : '')
            // Busiest daily queues first (item 44): money in/out, then
            // draws and winners, then the support inbox.
            ->navigationGroups(['Finance', 'Raffles', 'Community', 'Growth', 'Support', 'Site', 'Users', 'System'])
            ->discoverResources(in: app_path('Filament/Resources'), for: 'App\\Filament\\Resources')
            ->discoverPages(in: app_path('Filament/Pages'), for: 'App\\Filament\\Pages')
            ->pages([
                Pages\Dashboard::class,
            ])
            ->discoverWidgets(in: app_path('Filament/Widgets'), for: 'App\\Filament\\Widgets')
            // RaffleKings' own dashboard (item 45): what needs attention,
            // today's numbers and the 14-day trend — discovered from
            // app/Filament/Widgets above. Filament's default "about
            // Filament" boxes are gone.
            ->widgets([])
            ->middleware([
                EncryptCookies::class,
                AddQueuedCookiesToResponse::class,
                AttachExtraCookies::class,
                StartSession::class,
                ShareErrorsFromSession::class,
                VerifyCsrfToken::class,
                SubstituteBindings::class,
                DisableBladeIconComponents::class,
                DispatchServingFilamentEvent::class,
            ])
            ->authMiddleware([
                Authenticate::class,
            ])
            // Staff who never typed the emailed code (when two-step sign-in
            // is on) go back to sign in. Persistent: also checked on every
            // click inside a page, not just when a page first loads.
            ->authMiddleware([
                RequireStaffTwoStep::class,
            ], isPersistent: true);
    }
}
