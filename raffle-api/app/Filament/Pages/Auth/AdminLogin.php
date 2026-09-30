<?php

namespace App\Filament\Pages\Auth;

use App\Models\Admin\LoginEvent;
use App\Models\Legacy\WpUser;
use App\Services\AdminAuditLogService;
use App\Services\Auth\LoginService;
use App\Services\Auth\TurnstileVerifier;
use App\Services\Auth\WordPressCookieFactory;
use DanHarrin\LivewireRateLimiting\Exceptions\TooManyRequestsException;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\ViewField;
use Filament\Forms\Form;
use Filament\Http\Responses\Auth\Contracts\LoginResponse;
use Filament\Pages\Auth\Login;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Validation\ValidationException;

/**
 * The admin's own sign-in page (/admin/login). Same accounts and the same
 * password check as the customer site (App\Services\Auth\LoginService, the
 * WordPress login cookie), but only staff get in (App\Auth\StaffRoles).
 * Five tries a minute, the Turnstile check when it's switched on for
 * log-in (Settings → Security), and every staff sign-in is audit-logged.
 */
class AdminLogin extends Login
{
    protected static string $view = 'filament.auth.admin-login';

    protected static string $layout = 'filament.auth.layout';

    /** Someone already signed in on the site with a customer account. */
    public ?string $signedInAs = null;

    public function mount(): void
    {
        $user = Filament::auth()->user();

        if ($user instanceof WpUser && $user->staffRole() !== null) {
            redirect()->intended(Filament::getUrl());

            return;
        }

        $this->signedInAs = $user instanceof WpUser ? ($user->display_name ?: $user->user_login) : null;
        $this->form->fill();
    }

    public function getTitle(): string|Htmlable
    {
        return 'Staff sign in';
    }

    public function getHeading(): string|Htmlable
    {
        return 'Staff sign in';
    }

    public static function turnstileKey(): ?string
    {
        return app(TurnstileVerifier::class)->enabled('login') ? config('services.turnstile.site_key') : null;
    }

    public function form(Form $form): Form
    {
        return $form->schema([
            TextInput::make('login')
                ->label('Email or username')
                ->required()
                ->autocomplete('username')
                ->autofocus()
                ->extraInputAttributes(['tabindex' => 1, 'autocapitalize' => 'none', 'spellcheck' => 'false']),
            TextInput::make('password')
                ->label('Password')
                ->password()
                ->revealable()
                ->required()
                ->autocomplete('current-password')
                ->extraInputAttributes(['tabindex' => 2]),
            Hidden::make('turnstile_token'),
            ViewField::make('turnstile')
                ->view('filament.auth.turnstile', ['siteKey' => static::turnstileKey()])
                ->visible(fn () => static::turnstileKey() !== null)
                ->dehydrated(false),
        ])->statePath('data');
    }

    public function authenticate(): ?LoginResponse
    {
        try {
            $this->rateLimit(5);
        } catch (TooManyRequestsException $exception) {
            $this->getRateLimitedNotification($exception)?->send();

            return null;
        }

        $data = $this->form->getState();
        $request = request();

        if (! app(TurnstileVerifier::class)->verify($data['turnstile_token'] ?? null, $request->ip(), 'login')) {
            throw ValidationException::withMessages(['data.login' => 'Please complete the security check and try again.']);
        }

        try {
            $result = app(LoginService::class)->login(trim($data['login']), $data['password'], $request->ip(), $request->userAgent(), place: 'admin', recordSuccess: false);
        } catch (ValidationException $e) {
            throw ValidationException::withMessages(['data.login' => collect($e->errors())->flatten()->first()]);
        }

        /** @var WpUser $user */
        $user = $result['user'];

        if ($user->staffRole() === null) {
            LoginEvent::record($user->ID, trim($data['login']), false, 'admin', 'not_staff');

            // Right password, but a customer account: don't sign them in here.
            $parts = explode('|', $result['cookie']['value']);
            if (! empty($parts[2])) {
                app(LoginService::class)->logout($user, $parts[2]);
            }

            throw ValidationException::withMessages(['data.login' => 'This account doesn\'t have access to the admin. Ask an owner to add you under Staff & roles.']);
        }

        $cookies = app(WordPressCookieFactory::class);
        $name = app('wordpress.auth_cookie_name');
        Cookie::queue($cookies->make($name, $result['cookie']['value'], $result['cookie']['expiration']));
        foreach ($cookies->forgetLeftovers($name, $request->getHost()) as $leftover) {
            Cookie::queue($leftover);
        }

        Filament::auth()->setUser($user);
        session()->regenerate();

        LoginEvent::record($user->ID, trim($data['login']), true, 'admin');

        app(AdminAuditLogService::class)->record($user, 'staff.signed_in', WpUser::class, $user->ID, [
            'ip' => $request->ip(),
            'device' => str($request->userAgent())->limit(120)->toString(),
        ]);

        return app(LoginResponse::class);
    }

    protected function getAuthenticateFormAction(): Action
    {
        return parent::getAuthenticateFormAction()->label('Sign in')->extraAttributes(['tabindex' => 3]);
    }
}
