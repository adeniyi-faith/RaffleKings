<div>
    <div style="margin-bottom:24px">
        <h1 class="text-2xl font-bold tracking-tight text-gray-950 dark:text-white">Staff sign in</h1>
        <p style="margin-top:6px" class="text-sm text-gray-500 dark:text-gray-400">Use your {{ config('app.name') }} account. Only staff can get in.</p>
    </div>

    @if ($signedInAs)
        <div style="margin-bottom:20px" class="flex items-start gap-2 rounded-xl px-3 py-2.5 text-sm" style="background:rgba(var(--warning-500),.12);color:rgb(var(--warning-700))">
            <x-filament::icon icon="heroicon-m-information-circle" class="mt-0.5 h-4 w-4 shrink-0" />
            <span>You're signed in on the site as <b>{{ $signedInAs }}</b>, which isn't a staff account. Sign in with a staff account below.</span>
        </div>
    @endif

    <x-filament-panels::form id="form" wire:submit="authenticate">
        {{ $this->form }}

        <x-filament-panels::form.actions :actions="$this->getCachedFormActions()" :full-width="true" />
    </x-filament-panels::form>

    <div style="margin-top:24px" class="flex items-center justify-between gap-3 text-sm">
        <a href="{{ url('/forgot-password') }}" class="font-medium" style="color:rgb(var(--primary-600))">Forgot password?</a>
        <a href="{{ url('/') }}" class="text-gray-500 hover:text-gray-700 dark:text-gray-400 dark:hover:text-gray-200">Back to the site</a>
    </div>

    <p style="margin-top:28px" class="flex items-center justify-center gap-1.5 text-xs text-gray-400 dark:text-gray-500">
        <x-filament::icon icon="heroicon-m-lock-closed" class="h-3.5 w-3.5" />
        Secure sign-in. Every staff sign-in is recorded.
    </p>
</div>
