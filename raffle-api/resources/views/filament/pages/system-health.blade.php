<x-filament-panels::page>
    <div wire:poll.30s class="grid gap-6">
        <div class="rk-stats">
            @foreach ([
                ['Problems', $critical, $critical ? 'danger' : 'success', $critical ? 'Need fixing' : 'None'],
                ['Warnings', $warnings, $warnings ? 'warning' : 'success', $warnings ? 'Worth a look' : 'None'],
                ['Background tasks last ran', $lastRun, str_contains($lastRun, 'second') || str_contains($lastRun, '1 minute') ? 'success' : 'danger', 'Should be under a minute'],
                ['Emails & alerts waiting', $waiting ?? '—', 'gray', ($failed->count() ? $failed->count().' failed' : 'none failed')],
            ] as [$label, $value, $color, $hint])
                <div class="fi-wi-stats-overview-stat rounded-xl bg-white p-4 shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10">
                    <p class="text-xs font-medium text-gray-500 dark:text-gray-400">{{ $label }}</p>
                    <p class="mt-1 text-xl font-semibold" style="color: rgb(var(--{{ $color }}-600))">{{ $value }}</p>
                    <p class="text-xs text-gray-500 dark:text-gray-400">{{ $hint }}</p>
                </div>
            @endforeach
        </div>

        <x-filament::section icon="heroicon-o-clipboard-document-check">
            <x-slot name="heading">Checks</x-slot>
            <x-slot name="description">Settings, database and background tasks. Missing keys can be added under System → Settings.</x-slot>
            <div class="divide-y divide-gray-100 dark:divide-white/5">
                @foreach ($checks as [$status, $name, $detail])
                    <div class="flex items-start gap-3 py-2.5">
                        <x-filament::badge :color="match ($status) { 'OK' => 'success', 'WARNING' => 'warning', default => 'danger' }" class="mt-0.5 shrink-0">
                            {{ match ($status) { 'OK' => 'OK', 'WARNING' => 'Warning', default => 'Problem' } }}
                        </x-filament::badge>
                        <div class="min-w-0">
                            <p class="text-sm font-medium" style="overflow-wrap:anywhere">{{ $name }}</p>
                            <p class="text-sm text-gray-500 dark:text-gray-400">{{ $detail }}</p>
                        </div>
                    </div>
                @endforeach
            </div>
        </x-filament::section>

        <x-filament::section icon="heroicon-o-envelope-open" :icon-color="$failed->count() ? 'danger' : 'success'">
            <x-slot name="heading">Failed emails & alerts ({{ $failed->count() }})</x-slot>
            <x-slot name="description">Background jobs that gave up after 3 tries — usually an email the provider refused. Fix the cause (e.g. email settings), then Retry.</x-slot>
            @if ($failed->count())
                <x-slot name="headerEnd">
                    <x-filament::button size="sm" color="gray" icon="heroicon-m-arrow-path" wire:click="retryAll" wire:confirm="Retry every failed job now?">Retry all</x-filament::button>
                </x-slot>
            @endif
            <div class="divide-y divide-gray-100 dark:divide-white/5">
                @forelse ($failed as $job)
                    <div class="py-3">
                        <div class="flex items-start justify-between gap-3">
                            <div class="min-w-0">
                                <p class="text-sm font-semibold">{{ $job->name }}</p>
                                <p class="text-xs text-gray-500 dark:text-gray-400">{{ $job->failed_at->diffForHumans() }}</p>
                            </div>
                            <div class="flex shrink-0 gap-2">
                                <x-filament::button size="xs" color="primary" wire:click="retry('{{ $job->uuid }}')">Retry</x-filament::button>
                                <x-filament::icon-button icon="heroicon-m-trash" color="gray" size="sm" label="Remove" wire:click="forget('{{ $job->uuid }}')" wire:confirm="Remove this failed job for good?" />
                            </div>
                        </div>
                        <p class="mt-1 text-xs text-danger-600 dark:text-danger-400" style="overflow-wrap:anywhere">{{ $job->error }}</p>
                    </div>
                @empty
                    <p class="text-sm text-gray-500 dark:text-gray-400">Nothing has failed. Emails and alerts are going out.</p>
                @endforelse
            </div>
        </x-filament::section>

        <x-filament::section icon="heroicon-o-bug-ant" :icon-color="$errors->count() ? 'warning' : 'success'">
            <x-slot name="heading">Recent site errors ({{ $errors->count() }})</x-slot>
            <x-slot name="description">Unexpected server errors customers or staff ran into, grouped when the same thing happens again. Share these with your developer.</x-slot>
            @if ($errors->count())
                <x-slot name="headerEnd">
                    <x-filament::button size="sm" color="gray" wire:click="clearErrors" wire:confirm="Clear the list? (Errors still happening will appear again.)">Clear list</x-filament::button>
                </x-slot>
            @endif
            <div class="divide-y divide-gray-100 dark:divide-white/5">
                @forelse ($errors as $error)
                    <details class="group py-3">
                        <summary class="flex cursor-pointer list-none items-start justify-between gap-3">
                            <span class="min-w-0">
                                <span class="block text-sm font-semibold" style="overflow-wrap:anywhere">{{ $error->message }}</span>
                                <span class="block text-xs text-gray-500 dark:text-gray-400">Last {{ $error->last_seen_at?->diffForHumans() }} · first {{ $error->first_seen_at?->format('j M, H:i') }}</span>
                            </span>
                            <x-filament::badge :color="$error->occurrences > 10 ? 'danger' : 'warning'" class="shrink-0">×{{ number_format($error->occurrences) }}</x-filament::badge>
                        </summary>
                        <pre class="mt-2 overflow-x-auto whitespace-pre-wrap rounded-lg bg-gray-50 p-3 text-xs text-gray-700 dark:bg-white/5 dark:text-gray-300">{{ $error->details }}</pre>
                    </details>
                @empty
                    <p class="text-sm text-gray-500 dark:text-gray-400">No server errors recorded.</p>
                @endforelse
            </div>
        </x-filament::section>
    </div>
</x-filament-panels::page>
