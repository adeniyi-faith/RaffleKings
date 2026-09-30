<x-filament-panels::page>
    {{ $this->form }}

    @if ($problem)
        <p class="text-sm text-danger-600">{{ $problem }}</p>
    @else
        @if ($summary)
            @php
                $tiles = [
                    ['Ticket sales', 'sales', true],
                    ['Top-ups in', 'topups', true],
                    ['Withdrawals paid', 'withdrawals', true],
                    ['Prizes credited', 'prizes', false],
                    ['Refunds', 'refunds', false],
                    ['Bonuses and commissions given', 'given', false],
                    ['What the site kept', 'kept', true],
                ];
            @endphp
            <div class="rk-stats">
                @foreach ($tiles as [$label, $key, $goodWhenUp])
                    @php
                        $now = $summary[$key];
                        $before = $previous[$key];
                        $change = $before != 0 ? round(($now - $before) / abs($before) * 100) : null;
                        $good = $goodWhenUp ? $now >= $before : null;
                    @endphp
                    <div class="rounded-xl bg-white p-4 shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10">
                        <p class="text-xs font-medium text-gray-500 dark:text-gray-400">{{ $label }}</p>
                        <p class="mt-1 text-lg font-semibold tabular-nums">₦{{ number_format($now) }}</p>
                        <p class="mt-0.5 text-xs {{ $good === null ? 'text-gray-500' : ($good ? 'text-success-600' : 'text-danger-600') }}">
                            Before: ₦{{ number_format($before) }}@if ($change !== null) ({{ $change > 0 ? '+' : '' }}{{ $change }}%)@endif
                        </p>
                    </div>
                @endforeach
            </div>
        @endif

        @if (! empty($notes))
            <p class="text-xs text-gray-500 dark:text-gray-400">{{ $notes }}</p>
        @endif

        <x-filament::section>
            <div class="overflow-x-auto" style="margin-inline:-4px">
                <table class="w-full text-sm" style="white-space:nowrap">
                    <thead class="text-gray-500">
                        <tr>
                            @foreach ($report['headings'] as $i => $heading)
                                <th class="px-2 py-1 {{ $i === 0 ? 'text-start' : 'text-end' }}">{{ $heading }}</th>
                            @endforeach
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($report['rows'] as $row)
                            <tr class="border-t border-gray-100 dark:border-white/5">
                                @foreach ($row as $i => $cell)
                                    <td class="px-2 py-1 {{ $i === 0 ? 'text-start font-medium' : 'text-end tabular-nums' }}">
                                        {{ is_numeric($cell) ? number_format((float) $cell, fmod((float) $cell, 1) == 0 ? 0 : 2) : $cell }}
                                    </td>
                                @endforeach
                            </tr>
                        @empty
                            <tr><td colspan="{{ count($report['headings']) }}" class="px-2 py-4 text-center text-gray-500">Nothing happened in these days.</td></tr>
                        @endforelse
                    </tbody>
                    @if ($report['rows'] !== [])
                        <tfoot>
                            <tr class="border-t-2 border-gray-200 font-semibold dark:border-white/10">
                                @foreach ($report['totals'] as $i => $cell)
                                    <td class="px-2 py-1 {{ $i === 0 ? 'text-start' : 'text-end tabular-nums' }}">
                                        {{ is_numeric($cell) ? number_format((float) $cell, fmod((float) $cell, 1) == 0 ? 0 : 2) : $cell }}
                                    </td>
                                @endforeach
                            </tr>
                        </tfoot>
                    @endif
                </table>
            </div>
        </x-filament::section>
    @endif
</x-filament-panels::page>
