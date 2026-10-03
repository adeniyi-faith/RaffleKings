<x-filament-panels::page>
    @php
        $report = $this->report();
        $snap = $this->snapshot();
        $o = $snap['overview'] ?? [];
        $p = $snap['players'] ?? [];
        $pending = $report?->isPending();
    @endphp

    <div @if ($pending) wire:poll.5s @endif class="space-y-6">
        <x-filament::section heading="Ask the advisor" description="Leave the box empty for general advice, or ask about something specific.">
            @if (! $this->aiAvailable())
                <p class="text-sm text-gray-500">Add a Claude (Anthropic) key in Settings → AI → "Claude (raffle advisor)" to get advice. The numbers below work without it.</p>
            @else
                <form wire:submit="askAdvisor" class="space-y-3">
                    <textarea wire:model="focus" rows="2" maxlength="1000"
                        class="block w-full rounded-lg border-gray-300 text-sm shadow-sm dark:border-white/10 dark:bg-white/5"
                        placeholder="e.g. Plan next week's raffles. Or: should we try a daily drop? Or: how do we win back players who stopped coming?"></textarea>
                    <x-filament::button type="submit" icon="heroicon-o-sparkles" wire:loading.attr="disabled" :disabled="$pending">
                        Get fresh advice
                    </x-filament::button>
                </form>
            @endif
        </x-filament::section>

        <x-filament::section heading="What the advisor sees" description="Totals for the last {{ $snap['period_days'] ?? 90 }} days. No names or contact details are ever sent.">
            <div class="grid grid-cols-2 gap-3 lg:grid-cols-4">
                @foreach ([
                    ['Ticket sales', '₦'.number_format($o['ticket_sales'] ?? 0)],
                    ['Tickets sold', number_format($o['tickets_sold'] ?? 0)],
                    ['Players', number_format($o['different_players'] ?? 0)],
                    ['Avg. tickets per order', $o['average_tickets_per_order'] ?? 0],
                    ['New players', number_format($p['new_players_in_period'] ?? 0)],
                    ['Returning players', number_format($p['returning_players_in_period'] ?? 0)],
                    ['Played 4+ raffles', number_format($p['played_4_or_more_raffles'] ?? 0)],
                    ['Raffles on sale now', $snap['raffles_on_sale_now'] ?? 0],
                ] as [$label, $value])
                    <div class="rounded-xl bg-white p-4 shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10">
                        <p class="text-xs font-medium text-gray-500 dark:text-gray-400">{{ $label }}</p>
                        <p class="mt-1 text-lg font-semibold tabular-nums">{{ $value }}</p>
                    </div>
                @endforeach
            </div>
        </x-filament::section>

        @if ($report)
            <x-filament::section
                :heading="'Advice from '.$report->created_at->timezone(config('raffles.timezone'))->format('j M Y, g:ia')"
                :description="$report->focus ? 'You asked: '.$report->focus : ($report->trigger === 'weekly' ? 'Weekly check-in' : null)">
                @if ($pending)
                    <div class="flex items-center gap-3 text-sm text-gray-600 dark:text-gray-300">
                        <x-filament::loading-indicator class="h-5 w-5" />
                        The advisor is studying the numbers and writing its advice. This usually takes a minute or two.
                    </div>
                @elseif ($report->status === 'failed')
                    <p class="text-sm text-danger-600">This report could not be written: {{ $report->error }}</p>
                @else
                    <p class="whitespace-pre-line text-sm leading-6">{{ $report->summary }}</p>

                    <div class="mt-6 space-y-4">
                        @foreach ((array) $report->recommendations as $i => $rec)
                            <div class="rounded-xl p-4 ring-1 ring-gray-950/10 dark:ring-white/10">
                                <div class="flex flex-wrap items-center gap-2">
                                    <h3 class="text-base font-semibold">{{ $i + 1 }}. {{ $rec['title'] ?? '' }}</h3>
                                    <x-filament::badge color="gray">{{ \App\Services\Advisor\RaffleAdvisor::KINDS[$rec['kind'] ?? ''] ?? 'Idea' }}</x-filament::badge>
                                    @if ($rec['ready_today'] ?? false)
                                        <x-filament::badge color="success">Can run today</x-filament::badge>
                                    @else
                                        <x-filament::badge color="warning">Needs building</x-filament::badge>
                                    @endif
                                </div>

                                <dl class="mt-3 space-y-2 text-sm">
                                    @foreach (['what' => 'What to do', 'why' => 'Why it should work', 'risks' => 'Risks', 'fairness' => 'Keeping it fair', 'measure' => 'How to tell if it worked'] as $key => $label)
                                        @if (filled($rec[$key] ?? null))
                                            <div><dt class="font-medium text-gray-500 dark:text-gray-400">{{ $label }}</dt><dd>{{ $rec[$key] }}</dd></div>
                                        @endif
                                    @endforeach
                                    @if (! empty($rec['steps']))
                                        <div>
                                            <dt class="font-medium text-gray-500 dark:text-gray-400">Steps</dt>
                                            <dd><ol class="ms-5 list-decimal">@foreach ($rec['steps'] as $step)<li>{{ $step }}</li>@endforeach</ol></dd>
                                        </div>
                                    @endif
                                </dl>

                                @if (is_array($rec['raffle_draft'] ?? null))
                                    @php $d = $rec['raffle_draft']; @endphp
                                    <div class="mt-4 rounded-lg bg-gray-50 p-3 text-sm dark:bg-white/5">
                                        <p class="font-medium">Ready-made raffle: {{ $d['title'] ?? '' }}</p>
                                        <p class="mt-1 text-gray-600 dark:text-gray-300">
                                            ₦{{ number_format((float) ($d['ticket_price'] ?? 0)) }} a ticket · {{ number_format((int) ($d['tickets_available'] ?? 0)) }} tickets ·
                                            {{ ($d['is_flash'] ?? false) ? '⚡ flash, '.($d['flash_hours'] ?? 1).' hours' : ($d['sales_days'] ?? 7).' days' }} ·
                                            grand prize: {{ $d['grand_prize'] ?? '' }}
                                        </p>
                                        @if (! empty($d['prize_tiers']))
                                            <ul class="mt-1 text-gray-600 dark:text-gray-300">
                                                @foreach ($d['prize_tiers'] as $t)
                                                    <li>{{ $t['tier_name'] ?? '' }}: {{ $t['prize_description'] ?? '' }} × {{ $t['winner_count'] ?? 1 }}</li>
                                                @endforeach
                                            </ul>
                                        @endif

                                        <div class="mt-3">
                                            @if (! empty($rec['opened_raffle_id']))
                                                <x-filament::link :href="\App\Filament\Resources\RaffleResource::getUrl('edit', ['record' => $rec['opened_raffle_id']])" icon="heroicon-o-arrow-right">
                                                    Opened as a draft: view it
                                                </x-filament::link>
                                            @else
                                                <x-filament::button size="sm" icon="heroicon-o-plus" wire:click="openDraft({{ $i }})" wire:loading.attr="disabled"
                                                    wire:confirm="Create this raffle as a draft? It stays hidden from customers until you publish it.">
                                                    Open as a draft raffle
                                                </x-filament::button>
                                            @endif
                                        </div>
                                    </div>
                                @endif
                            </div>
                        @endforeach
                    </div>
                @endif
            </x-filament::section>
        @endif

        @if ($this->history()->count() > 1)
            <x-filament::section heading="Earlier advice" collapsible collapsed>
                <ul class="divide-y divide-gray-100 text-sm dark:divide-white/10">
                    @foreach ($this->history() as $past)
                        <li class="flex items-center justify-between gap-3 py-2">
                            <span>
                                {{ $past->created_at->timezone(config('raffles.timezone'))->format('j M Y, g:ia') }}
                                · {{ $past->trigger === 'weekly' ? 'Weekly check-in' : \Illuminate\Support\Str::limit($past->focus ?: 'General advice', 60) }}
                                @if ($past->status !== 'ready') ({{ $past->status === 'pending' ? 'writing…' : 'failed' }}) @endif
                            </span>
                            <x-filament::button size="xs" color="gray" wire:click="showReport({{ $past->id }})">Show</x-filament::button>
                        </li>
                    @endforeach
                </ul>
            </x-filament::section>
        @endif
    </div>
</x-filament-panels::page>
