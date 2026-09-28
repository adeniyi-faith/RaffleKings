{{-- Shown at the top of every admin page while maintenance mode is on. --}}
<div class="mb-4 flex items-start gap-3 rounded-xl px-4 py-3 text-sm font-medium" style="background:rgba(var(--danger-600),.1);color:rgb(var(--danger-700));box-shadow:inset 0 0 0 1px rgba(var(--danger-600),.25)">
    <x-filament::icon icon="heroicon-m-wrench-screwdriver" class="mt-0.5 h-5 w-5 shrink-0" />
    <span>
        Maintenance mode is ON — customers see the "back soon" page{{ ($back = app(\App\Services\Maintenance::class)->backAt()) ? ' until '.$back->tz(config('raffles.timezone'))->format('D j M, H:i') : '' }}.
        <a href="{{ \App\Filament\Pages\Settings::getUrl() }}" class="underline">Change it in Settings → On / off</a>.
    </span>
</div>
