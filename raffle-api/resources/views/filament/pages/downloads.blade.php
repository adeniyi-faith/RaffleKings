<x-filament-panels::page>
    <x-filament-panels::form wire:submit="download">
        {{ $this->form }}

        @if ($totals)
            <div class="rk-stats">
                @foreach ([
                    ['Ticket sales', $totals['sales']],
                    ['Money in (top-ups)', $totals['topups']],
                    ['Money out (withdrawals)', $totals['withdrawals']],
                    ['Prizes credited', $totals['prizes']],
                ] as [$label, $value])
                    <div class="rounded-xl bg-white p-4 shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10">
                        <p class="text-xs font-medium text-gray-500 dark:text-gray-400">{{ $label }}</p>
                        <p class="mt-1 text-lg font-semibold tabular-nums">₦{{ number_format($value) }}</p>
                    </div>
                @endforeach
            </div>
        @endif

        <x-filament-panels::form.actions :actions="$this->getFormActions()" />
    </x-filament-panels::form>
</x-filament-panels::page>
