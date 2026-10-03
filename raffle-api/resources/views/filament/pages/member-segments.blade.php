<x-filament-panels::page>
    @php
        $summary = $this->summary();
        $moves = $this->moves();
        $offers = $this->offers();
        $channels = $this->channels();
        $perf = $offers['performance'];
        $t = $perf['totals'];
        $card = 'rounded-xl bg-white p-4 shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10';
    @endphp

    <div class="space-y-6">
        <x-filament::section heading="Segments" :description="number_format($summary['total']).' customers sorted. Each customer is in exactly one segment. Arrows show who moved in or out in the last 7 days.'">
            <div class="grid grid-cols-1 gap-3 sm:grid-cols-2 xl:grid-cols-3">
                @foreach (\App\Services\Retention\MemberSegments::SEGMENTS as $key => [$name, $meaning])
                    @php $s = $summary['segments'][$key]; @endphp
                    <div class="{{ $card }} flex flex-col">
                        <div class="flex items-start justify-between gap-2">
                            <p class="text-sm font-semibold">{{ $name }}</p>
                            <p class="text-xl font-semibold tabular-nums">{{ number_format($s['count']) }}</p>
                        </div>
                        <p class="mt-1 flex-1 text-xs text-gray-500 dark:text-gray-400">{{ $meaning }}</p>
                        <div class="mt-3 flex items-center justify-between text-xs">
                            <span class="tabular-nums text-gray-500">
                                <span class="text-success-600">↑ {{ number_format($s['joined_7d']) }} in</span>
                                · <span class="text-danger-600">↓ {{ number_format($s['left_7d']) }} out</span>
                            </span>
                            @if ($s['count'] > 0)
                                <x-filament::link :href="$this->messageUrl($key)" icon="heroicon-o-megaphone" size="sm">Message them</x-filament::link>
                            @endif
                        </div>
                    </div>
                @endforeach
            </div>
        </x-filament::section>

        <div class="grid gap-6 lg:grid-cols-2">
            <x-filament::section heading="Biggest moves this week" description="Customers whose segment changed in the last 7 days.">
                @if ($moves === [])
                    <p class="text-sm text-gray-500">No moves yet. Moves show from the second nightly sort onwards.</p>
                @else
                    <ul class="divide-y divide-gray-100 text-sm dark:divide-white/5">
                        @foreach ($moves as $m)
                            <li class="flex items-center justify-between py-2">
                                <span>{{ $m['from'] }} → <span class="font-medium">{{ $m['to'] }}</span></span>
                                <span class="tabular-nums font-semibold">{{ number_format($m['count']) }}</span>
                            </li>
                        @endforeach
                    </ul>
                @endif
            </x-filament::section>

            <x-filament::section heading="Labels" description="Extra labels a customer can have several of. Combine them with segments in Message customers → Build my own group.">
                <ul class="divide-y divide-gray-100 text-sm dark:divide-white/5">
                    @foreach (\App\Services\Retention\MemberSegments::FLAGS as $key => [$name, $meaning])
                        <li class="flex items-center justify-between gap-3 py-2">
                            <span>
                                <span class="font-medium">{{ $name }}</span>
                                <span class="block text-xs text-gray-500 dark:text-gray-400">{{ $meaning }}</span>
                            </span>
                            <span class="flex shrink-0 items-center gap-3">
                                <span class="tabular-nums font-semibold">{{ number_format($summary['flags'][$key] ?? 0) }}</span>
                                @if (($summary['flags'][$key] ?? 0) > 0 && $key !== 'stopped_reminders')
                                    <x-filament::link :href="$this->messageUrl(flag: $key)" icon="heroicon-o-megaphone" size="sm">Message</x-filament::link>
                                @endif
                            </span>
                        </li>
                    @endforeach
                </ul>
            </x-filament::section>
        </div>

        <x-filament::section heading="Comeback offers, last 30 days"
            :description="$offers['enabled'] ? 'On. Personal, time-limited gifts for customers who are slipping away. Change the budgets in Settings → Reminders → Comeback offers.' : 'Off. Switch on in Settings → Reminders → Comeback offers after checking the budgets.'">
            <div class="grid grid-cols-2 gap-3 lg:grid-cols-6">
                @foreach ([
                    ['Offers sent', number_format($t['offers'])],
                    ['Claimed', number_format($t['claimed']).' ('.$this->percent($t['claimed'], $t['offers']).')'],
                    ['Bought tickets after', number_format($t['came_back']).' ('.$this->percent($t['came_back'], $t['offers']).')'],
                    ['Ticket credit paid', '₦'.number_format($t['credit_paid'])],
                    ['Points paid', number_format($t['points_paid'])],
                    ['Ticket spend in 7 days after claiming', '₦'.number_format($t['spend_after'])],
                ] as [$label, $value])
                    <div class="{{ $card }}">
                        <p class="text-xs font-medium text-gray-500 dark:text-gray-400">{{ $label }}</p>
                        <p class="mt-1 text-lg font-semibold tabular-nums">{{ $value }}</p>
                    </div>
                @endforeach
            </div>

            <p class="mt-3 text-sm text-gray-600 dark:text-gray-300">
                This month: ₦{{ number_format($offers['budget']['money_used']) }} of ticket credit offered (₦{{ number_format($offers['budget']['money']) }} left),
                {{ number_format($offers['budget']['points_used']) }} points offered ({{ number_format($offers['budget']['points']) }} left).
                Offers that run out unclaimed give their budget back.
            </p>

            @if ($perf['by_segment'] !== [])
                <div class="mt-4 overflow-x-auto">
                    <table class="w-full text-left text-sm">
                        <thead class="text-xs text-gray-500">
                            <tr><th class="py-2 pr-4">Segment</th><th class="pr-4">Sent</th><th class="pr-4">Claimed</th><th class="pr-4">Bought after</th><th class="pr-4">Credit paid</th><th>Spend after</th></tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100 dark:divide-white/5">
                            @foreach ($perf['by_segment'] as $segment => $row)
                                <tr>
                                    <td class="py-2 pr-4">{{ \App\Services\Retention\MemberSegments::label($segment) }}</td>
                                    <td class="pr-4 tabular-nums">{{ number_format($row['offers']) }}</td>
                                    <td class="pr-4 tabular-nums">{{ $this->percent($row['claimed'], $row['offers']) }}</td>
                                    <td class="pr-4 tabular-nums">{{ $this->percent($row['came_back'], $row['offers']) }}</td>
                                    <td class="pr-4 tabular-nums">₦{{ number_format($row['credit_paid']) }}</td>
                                    <td class="tabular-nums">₦{{ number_format($row['spend_after']) }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </x-filament::section>

        <x-filament::section heading="Channels, last 30 days" description="Every message to customers (your messages and comeback offers). Tapped = they pressed the message's button. Email opens are a low estimate: many email apps hide them.">
            <div class="grid gap-3 md:grid-cols-3">
                @foreach (\App\Models\Retention\MessageDelivery::CHANNELS as $key => $name)
                    @php $c = $channels[$key]; @endphp
                    <div class="{{ $card }}">
                        <p class="text-sm font-semibold">{{ $name }}</p>
                        <dl class="mt-2 grid grid-cols-2 gap-y-1 text-sm">
                            <dt class="text-gray-500">Sent</dt><dd class="text-right tabular-nums">{{ number_format($c['sent']) }}</dd>
                            <dt class="text-gray-500">Waiting / failed</dt><dd class="text-right tabular-nums">{{ number_format($c['queued']) }} / {{ number_format($c['failed']) }}</dd>
                            <dt class="text-gray-500">{{ $key === 'inbox' ? 'Read' : 'Opened' }}</dt><dd class="text-right tabular-nums">{{ number_format($c['opened']) }} ({{ $this->percent($c['opened'], $c['sent']) }})</dd>
                            <dt class="text-gray-500">Tapped</dt><dd class="text-right tabular-nums">{{ number_format($c['clicked']) }} ({{ $this->percent($c['clicked'], $c['sent']) }})</dd>
                        </dl>
                    </div>
                @endforeach
            </div>
        </x-filament::section>
    </div>
</x-filament-panels::page>
