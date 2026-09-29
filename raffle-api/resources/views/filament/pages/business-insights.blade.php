<x-filament-panels::page>
    @php
        $rows = [
            ['sales', 'Ticket sales', true],
            ['purchases', 'Purchases', false],
            ['buyers', 'Customers who bought', false],
            ['tickets', 'Tickets sold', false],
            ['money_in', 'Money in (top-ups)', true],
            ['paid_out', 'Paid out (withdrawals)', true],
            ['sign_ups', 'New sign-ups', false],
            ['errors', 'Different site errors', false],
        ];
        $fmt = fn ($v, $money) => $money ? '₦'.number_format($v) : number_format($v);
    @endphp

    <div class="flex flex-wrap items-center gap-2">
        @foreach (\App\Services\Reports\BusinessInsights::PERIODS as $key => $label)
            <x-filament::button size="sm" :color="$period === $key ? 'primary' : 'gray'" wire:click="$set('period', '{{ $key }}')">
                {{ $label }}
            </x-filament::button>
        @endforeach
    </div>

    @if ($figures)
        <x-filament::section heading="The numbers" description="Compared with the same length of time just before.">
            <div class="grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-4">
                @foreach ($rows as [$key, $label, $money])
                    @php
                        $now = $figures['current'][$key];
                        $before = $figures['previous'][$key];
                        $change = $before > 0 ? round(($now - $before) / $before * 100) : null;
                        $good = $key === 'errors' ? $now <= $before : $now >= $before;
                    @endphp
                    <div class="rounded-xl bg-white p-4 shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10">
                        <p class="text-xs font-medium text-gray-500 dark:text-gray-400">{{ $label }}</p>
                        <p class="mt-1 text-lg font-semibold tabular-nums">{{ $fmt($now, $money) }}</p>
                        <p class="mt-0.5 text-xs {{ $good ? 'text-success-600' : 'text-danger-600' }}">
                            Before: {{ $fmt($before, $money) }}@if ($change !== null) ({{ $change > 0 ? '+' : '' }}{{ $change }}%)@endif
                        </p>
                    </div>
                @endforeach
            </div>
        </x-filament::section>

        <div class="grid grid-cols-1 gap-6 lg:grid-cols-2">
            <x-filament::section heading="Best-selling raffles">
                @forelse ($figures['top_raffles'] as $r)
                    <div class="flex justify-between border-b border-gray-100 py-2 text-sm last:border-0 dark:border-white/10">
                        <span>{{ $r['title'] }}</span>
                        <span class="font-semibold tabular-nums">{{ number_format($r['tickets']) }} tickets</span>
                    </div>
                @empty
                    <p class="text-sm text-gray-500">No tickets sold in this period.</p>
                @endforelse
            </x-filament::section>

            <x-filament::section heading="Waiting now">
                <div class="space-y-2 text-sm">
                    <p>Withdrawals waiting to be paid: <strong>{{ $figures['waiting']['withdrawals'] }}</strong> (₦{{ number_format($figures['waiting']['withdrawals_amount']) }})</p>
                    <p>Support tickets waiting for a reply: <strong>{{ $figures['waiting']['support_tickets'] }}</strong></p>
                </div>
            </x-filament::section>
        </div>

        <x-filament::section heading="AI summary" description="A short written summary of the numbers above, by Gemini.">
            @if (! $this->aiAvailable())
                <p class="text-sm text-gray-500">Add a Gemini key (GEMINI_API_KEY) to get a written summary. The numbers above work without it.</p>
            @elseif ($summary)
                <div class="prose max-w-none dark:prose-invert">{!! $summary !!}</div>
                <x-filament::button class="mt-4" size="sm" color="gray" wire:click="writeSummary" wire:loading.attr="disabled">Write it again</x-filament::button>
            @else
                <x-filament::button wire:click="writeSummary" wire:loading.attr="disabled" icon="heroicon-o-sparkles">
                    <span wire:loading.remove wire:target="writeSummary">Write a summary</span>
                    <span wire:loading wire:target="writeSummary">Writing…</span>
                </x-filament::button>
            @endif
        </x-filament::section>
    @endif
</x-filament-panels::page>
