<?php

namespace App\Filament\Pages\Auth;

use App\Http\Middleware\AttachExtraCookies;
use App\Models\Admin\LoginEvent;
use App\Models\Legacy\WpUser;
use App\Services\AdminAuditLogService;
use App\Auth\WordPressAuthCookieIssuer;
use App\Services\Auth\LoginService;
use App\Services\Auth\StaffTwoStep;
use App\Services\Auth\TurnstileVerifier;
use App\Services\Auth\WordPressCookieFactory;
use DanHarrin\LivewireRateLimiting\Exceptions\TooManyRequestsException;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\ViewField;
use Throwable;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
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
 *
 * With two-step sign-in on (App\Services\Auth\StaffTwoStep) the right
 * password only starts the sign-in: no login cookie is given out until the
 * 6-digit code emailed to the staff member is typed in ($step = 'code').
 */
class AdminLogin extends Login
{
    protected static string $view = 'filament.auth.admin-login';

    protected static string $layout = 'filament.auth.layout';

    /** Someone already signed in on the site with a customer account. */
    public ?string $signedInAs = null;

    /** password → code (only when two-step sign-in is on) */
    public string $step = 'password';

    /** Signed in already, but two-step sign-in was switched on since: they must go through the code once. */
    public bool $needsCode = false;

    public ?string $codeSentTo = null;

    public function mount(): void
    {
        $user = Filament::auth()->user();

        if ($user instanceof WpUser && $user->staffRole() !== null) {
            if (! StaffTwoStep::required(request(), $user)) {
                redirect()->intended(Filament::getUrl());

                return;
            }

            $this->needsCode = true;
        } else {
            $this->signedInAs = $user instanceof WpUser ? ($user->display_name ?: $user->user_login) : null;
        }

        // A page refresh in the middle of the code step stays on it.
        $pending = app(StaffTwoStep::class)->pendingUser();
        $this->step = $pending ? 'code' : 'password';
        $this->codeSentTo = $pending ? StaffTwoStep::maskEmail($pending->user_email) : null;

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
                ->visible(fn () => $this->step === 'password')
                ->required()
                ->autocomplete('username')
                ->autofocus()
                ->extraInputAttributes(['tabindex' => 1, 'autocapitalize' => 'none', 'spellcheck' => 'false']),
            TextInput::make('password')
                ->label('Password')
                ->visible(fn () => $this->step === 'password')
                ->password()
                ->revealable()
                ->required()
                ->autocomplete('current-password')
                ->extraInputAttributes(['tabindex' => 2]),
            TextInput::make('code')
                ->label('6-digit code from your email')
                ->visible(fn () => $this->step === 'code')
                ->required()
                ->autocomplete('one-time-code')
                ->autofocus()
                ->rule('regex:/^\s*\d{3}\s?\d{3}\s*$/')
                ->validationMessages(['regex' => 'The code is 6 numbers.'])
                ->extraInputAttributes(['inputmode' => 'numeric', 'maxlength' => 8, 'style' => 'letter-spacing:.3em;text-align:center;font-size:1.25rem']),
            Hidden::make('turnstile_token'),
            ViewField::make('turnstile')
                ->view('filament.auth.turnstile', ['siteKey' => static::turnstileKey()])
                ->visible(fn () => static::turnstileKey() !== null && $this->step === 'password')
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

        $twoStep = app(StaffTwoStep::class);

        // A page still showing the code box after its sign-in ran out on the server: start again.
        if ($this->step === 'code' && ! $twoStep->pending()) {
            $this->step = 'password';
            $this->codeSentTo = null;
            $this->form->fill();

            throw ValidationException::withMessages(['data.login' => 'That sign-in ran out of time. Sign in again.']);
        }

        $data = $this->form->getState();
        $request = request();

        // Second step: the emailed code. (What is really pending is kept on the server, not in the page.)
        if ($this->step === 'code') {
            return $this->verifyCode((string) ($data['code'] ?? ''));
        }

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
            $this->revoke($user, $result['cookie']['value']);

            throw ValidationException::withMessages(['data.login' => 'This account doesn\'t have access to the admin. Ask an owner to add you under Staff & roles.']);
        }

        if (StaffTwoStep::enabled()) {
            // Right password, staff account: no login cookie yet. Ask for the emailed code.
            $this->revoke($user, $result['cookie']['value']);

            try {
                $twoStep->start($user, $request->ip(), str($request->userAgent())->limit(120)->toString());
            } catch (Throwable $e) {
                report($e);

                throw ValidationException::withMessages(['data.login' => 'The password is right, but we couldn\'t email your sign-in code. Try again in a minute, or ask an owner for help.']);
            }

            $this->step = 'code';
            $this->codeSentTo = StaffTwoStep::maskEmail($user->user_email);
            $this->form->fill();

            return null;
        }

        return $this->finishSignIn($user, $result['cookie'], trim($data['login']));
    }

    private function verifyCode(string $typed): ?LoginResponse
    {
        $twoStep = app(StaffTwoStep::class);
        $user = $twoStep->pendingUser();
        $request = request();
        $identifier = $user?->user_email ?? '';

        switch ($twoStep->check(preg_replace('/\D/', '', $typed))) {
            case 'ok':
                $cookie = app(WordPressAuthCookieIssuer::class)->issue($user, ttlSeconds: 14 * 24 * 60 * 60, ip: $request->ip(), userAgent: $request->userAgent());
                Cookie::queue($twoStep->markVerified($user));

                return $this->finishSignIn($user, $cookie, $identifier, twoStep: true);

            case 'wrong':
                LoginEvent::record($user?->ID, $identifier, false, 'admin', 'two_step_failed');
                $left = $twoStep->triesLeft();

                throw ValidationException::withMessages(['data.code' => 'That code isn\'t right. '.$left.' '.($left === 1 ? 'try' : 'tries').' left.']);

            default: // expired, locked, or nothing pending
                LoginEvent::record($user?->ID, $identifier, false, 'admin', 'two_step_failed');
                $this->step = 'password';
                $this->codeSentTo = null;
                $this->form->fill();

                throw ValidationException::withMessages(['data.login' => 'That sign-in ran out of time or had too many wrong codes. Sign in again.']);
        }
    }

    /** "Send me a new code" on the code step. */
    public function resendCode(): void
    {
        $sent = false;

        try {
            $sent = app(StaffTwoStep::class)->resend(request()->ip(), str(request()->userAgent())->limit(120)->toString());
        } catch (Throwable $e) {
            report($e);
        }

        Notification::make()
            ->title($sent ? 'A new code is on its way' : 'Please wait a little before asking again')
            ->body($sent ? 'Use the newest one. Older codes no longer work.' : 'You can ask for up to '.StaffTwoStep::MAX_SENDS.' codes per sign-in, 30 seconds apart.')
            ->{$sent ? 'success' : 'warning'}()->send();
    }

    /** "Start again" on the code step. */
    public function startAgain(): void
    {
        app(StaffTwoStep::class)->cancel();
        $this->step = 'password';
        $this->codeSentTo = null;
        $this->form->fill();
    }

    /** Take back the login cookie issued a moment ago (used when the sign-in isn't allowed to finish yet). */
    private function revoke(WpUser $user, string $cookieValue): void
    {
        $parts = explode('|', $cookieValue);

        if (! empty($parts[2])) {
            app(LoginService::class)->logout($user, $parts[2]);
        }
    }

    /** @param  array{value: string, expiration: int}  $cookie */
    private function finishSignIn(WpUser $user, array $cookie, string $identifier, bool $twoStep = false): LoginResponse
    {
        $request = request();
        $cookies = app(WordPressCookieFactory::class);
        $name = app('wordpress.auth_cookie_name');
        Cookie::queue($cookies->make($name, $cookie['value'], $cookie['expiration']));

        // NOT Cookie::queue: it keeps one cookie per name and path, so these
        // same-named "delete the old copy" cookies used to replace the login
        // cookie above, and sign-in just reloaded the page.
        foreach ($cookies->forgetLeftovers($name, $request->getHost()) as $leftover) {
            AttachExtraCookies::add($leftover);
        }

        Filament::auth()->setUser($user);
        session()->regenerate();

        LoginEvent::record($user->ID, $identifier, true, 'admin');

        app(AdminAuditLogService::class)->record($user, 'staff.signed_in', WpUser::class, $user->ID, [
            'ip' => $request->ip(),
            'device' => str($request->userAgent())->limit(120)->toString(),
            'two_step' => $twoStep,
        ]);

        return app(LoginResponse::class);
    }

    protected function getAuthenticateFormAction(): Action
    {
        return parent::getAuthenticateFormAction()->label(fn () => $this->step === 'code' ? 'Confirm code' : 'Sign in')->extraAttributes(['tabindex' => 3]);
    }
}
