@php
    $s = $statement;
    $naira = fn ($n) => '₦'.number_format((float) $n, fmod((float) $n, 1) == 0 ? 0 : 2);
    $steps = ['open' => 'Open', 'locked' => 'Locked', 'filed' => 'Filed', 'paid' => 'Paid'];
    $order = array_keys($steps);
    $at = array_search($s['status'], $order, true);
    $due = \Illuminate\Support\Carbon::parse($s['due_on']);
    $overdue = $s['status'] !== 'paid' && $s['ended'] && $due->isPast();
    $statusColor = ['open' => 'gray', 'locked' => 'info', 'filed' => 'warning', 'paid' => 'success'];
    $rule = $s['shortfall_rule'] === 'carry_forward' ? 'carried into next month' : 'forgotten (that month owes nothing)';
@endphp

<x-filament-panels::page>
    <div class="flex flex-wrap items-center justify-between gap-3">
        <div class="flex flex-wrap items-center gap-2" aria-label="Where this month is">
            @foreach ($steps as $key => $name)
                @php $i = array_search($key, $order, true); @endphp
                <x-filament::badge :color="$i < $at ? 'success' : ($i === $at ? 'warning' : 'gray')">
                    {{ $i < $at ? '✓ ' : '' }}{{ $name }}
                </x-filament::badge>
            @endforeach
            @if ($overdue)
                <x-filament::badge color="danger">Overdue since {{ $due->format('j M Y') }}</x-filament::badge>
            @endif
        </div>

        <div class="flex items-center gap-2">
            <label for="taxMonth" class="text-xs font-medium text-gray-500 dark:text-gray-400">Month</label>
            <x-filament::input.wrapper style="min-width:11rem">
                <x-filament::input.select id="taxMonth" wire:model.live="month">
                    @foreach ($months as $m)
                        <option value="{{ $m }}">{{ $label($m) }}</option>
                    @endforeach
                </x-filament::input.select>
            </x-filament::input.wrapper>
        </div>
    </div>

    {{-- Sales minus prizes, times the rate --}}
    <div class="rk-stats" aria-label="How the tax is worked out">
        <div class="rounded-xl bg-white p-4 shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10">
            <p class="text-xs font-medium text-gray-500 dark:text-gray-400">Ticket sales</p>
            <p class="mt-1 text-lg font-semibold tabular-nums">{{ $naira($s['net_sales']) }}</p>
            <p class="mt-0.5 text-xs text-gray-500">
                @if ($s['refunds'] > 0) {{ $naira($s['sales']) }} minus {{ $naira($s['refunds']) }} refunded @else money from tickets @endif
            </p>
        </div>
        <div class="rounded-xl bg-white p-4 shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10">
            <p class="text-xs font-medium text-gray-500 dark:text-gray-400">Prizes won</p>
            <p class="mt-1 text-lg font-semibold tabular-nums">− {{ $naira($s['prizes']) }}</p>
            <p class="mt-0.5 text-xs text-gray-500">counted from the day awarded</p>
        </div>
        <div class="rounded-xl bg-white p-4 shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10">
            <p class="text-xs font-medium text-gray-500 dark:text-gray-400">Taxable amount</p>
            <p class="mt-1 text-lg font-semibold tabular-nums">{{ $naira($s['taxable']) }}</p>
            <p class="mt-0.5 text-xs text-gray-500">
                @if ($s['margin'] < 0)
                    prizes beat sales by {{ $naira(-$s['margin']) }}: {{ $rule }}
                @elseif ($s['carried_in'] > 0)
                    after {{ $naira($s['carried_in']) }} shortfall brought in
                @else
                    sales minus prizes
                @endif
            </p>
        </div>
        <div class="rounded-xl bg-amber-50 p-4 shadow-sm ring-1 ring-amber-500/30 dark:bg-amber-500/10 dark:ring-amber-400/30">
            <p class="text-xs font-medium text-amber-700 dark:text-amber-300">Tax due at {{ rtrim(rtrim(number_format($s['rate'], 3), '0'), '.') }}%</p>
            <p class="mt-1 text-lg font-semibold tabular-nums text-amber-700 dark:text-amber-300">{{ $naira($s['tax_due']) }}</p>
            <p class="mt-0.5 text-xs text-amber-700/80 dark:text-amber-300/80">
                @if ($s['status'] === 'paid') paid in full @elseif ($s['status'] === 'open' && ! $s['ended']) so far, due {{ $due->format('j M Y') }} @else due {{ $due->format('j M Y') }} @endif
                @if ($s['carried_out'] > 0) · {{ $naira($s['carried_out']) }} carried on @endif
            </p>
        </div>
    </div>

    @if ($s['record'])
        <p class="text-xs text-gray-500 dark:text-gray-400">
            Locked {{ $s['record']->locked_at?->setTimezone(config('raffles.timezone'))->format('j M Y, g:ia') }} at {{ rtrim(rtrim(number_format($s['rate'], 3), '0'), '.') }}%, shortfall {{ $rule }}.
            @if ($s['record']->filed_at) Filed {{ $s['record']->filed_at->setTimezone(config('raffles.timezone'))->format('j M Y') }}@if ($s['record']->filing_reference) (ref {{ $s['record']->filing_reference }})@endif. @endif
            @if ($s['record']->paid_at) Paid {{ $naira($s['record']->paid_amount) }} on {{ $s['record']->paid_at->setTimezone(config('raffles.timezone'))->format('j M Y') }}@if ($s['record']->payment_reference) (ref {{ $s['record']->payment_reference }})@endif. @endif
        </p>
    @elseif ($cannotLock)
        <p class="text-xs text-gray-500 dark:text-gray-400">{{ $cannotLock }}</p>
    @endif

    @foreach ($attention as $note)
        <div class="rounded-xl bg-amber-50 px-4 py-3 text-sm text-amber-900 ring-1 ring-amber-500/20 dark:bg-amber-500/10 dark:text-amber-200 dark:ring-amber-400/20">{{ $note }}</div>
    @endforeach

    <x-filament::section heading="Every month" description="Tap a month to look at it.">
        <div class="overflow-x-auto" style="margin-inline:-4px">
            <table class="w-full text-sm" style="white-space:nowrap">
                <thead class="text-gray-500">
                    <tr>
                        <th class="px-2 py-1 text-start">Month</th>
                        <th class="px-2 py-1 text-end">Sales</th>
                        <th class="px-2 py-1 text-end">Prizes won</th>
                        <th class="px-2 py-1 text-end">Taxable</th>
                        <th class="px-2 py-1 text-end">Tax due</th>
                        <th class="px-2 py-1 text-end">Status</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($rows as $row)
                        <tr class="border-t border-gray-100 dark:border-white/5 {{ $row['period'] === $month ? 'bg-amber-50 dark:bg-amber-500/10' : '' }}">
                            <td class="px-2 py-1 text-start font-medium">
                                <button type="button" wire:click="selectMonth('{{ $row['period'] }}')" class="text-primary-600 dark:text-primary-400">{{ $label($row['period']) }}</button>
                            </td>
                            <td class="px-2 py-1 text-end tabular-nums">{{ $naira($row['net_sales']) }}</td>
                            <td class="px-2 py-1 text-end tabular-nums">{{ $naira($row['prizes']) }}</td>
                            <td class="px-2 py-1 text-end tabular-nums">{{ $naira($row['taxable']) }}</td>
                            <td class="px-2 py-1 text-end tabular-nums">{{ $naira($row['tax_due']) }}</td>
                            <td class="px-2 py-1 text-end"><x-filament::badge :color="$statusColor[$row['status']]" size="sm">{{ $steps[$row['status']] }}</x-filament::badge></td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </x-filament::section>

    <div class="grid gap-4" style="grid-template-columns:repeat(auto-fit,minmax(min(100%,22rem),1fr))">
        <x-filament::section :heading="'By raffle · '.$label($month)" description="The tax is worked out on the whole month, so a raffle that paid out more than it sold evens out against the others.">
            <div class="overflow-x-auto" style="margin-inline:-4px">
                <table class="w-full text-sm" style="white-space:nowrap">
                    <thead class="text-gray-500">
                        <tr><th class="px-2 py-1 text-start">Raffle</th><th class="px-2 py-1 text-end">Sales</th><th class="px-2 py-1 text-end">Prizes won</th><th class="px-2 py-1 text-end">Sales minus prizes</th></tr>
                    </thead>
                    <tbody>
                        @forelse ($s['by_raffle'] as $r)
                            <tr class="border-t border-gray-100 dark:border-white/5">
                                <td class="px-2 py-1 text-start font-medium">{{ $r['raffle'] }}</td>
                                <td class="px-2 py-1 text-end tabular-nums">{{ $naira($r['sales']) }}</td>
                                <td class="px-2 py-1 text-end tabular-nums">{{ $naira($r['prizes']) }}</td>
                                <td class="px-2 py-1 text-end tabular-nums {{ $r['sales'] - $r['prizes'] < 0 ? 'text-gray-500' : '' }}">{{ $naira($r['sales'] - $r['prizes']) }}</td>
                            </tr>
                        @empty
                            <tr><td colspan="4" class="px-2 py-3 text-center text-gray-500">No sales or prizes this month.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </x-filament::section>

        <x-filament::section heading="How it is set up">
            <dl class="grid gap-2 text-sm">
                <div class="flex justify-between gap-4"><dt class="text-gray-500">Tax rate</dt><dd class="font-medium tabular-nums">{{ rtrim(rtrim(number_format((float) config('gaming_tax.rate'), 3), '0'), '.') }}%</dd></div>
                <div class="flex justify-between gap-4"><dt class="text-gray-500">If prizes beat sales</dt><dd class="text-end font-medium">{{ config('gaming_tax.shortfall') === 'carry_forward' ? 'Carried into next month' : 'That month owes nothing' }}</dd></div>
                <div class="flex justify-between gap-4"><dt class="text-gray-500">Due on</dt><dd class="font-medium">the {{ \Illuminate\Support\Number::ordinal((int) config('gaming_tax.due_day')) }} of the next month</dd></div>
            </dl>
            <p class="mt-3 text-xs text-gray-500">A month keeps the rate and rule it was locked with. Confirm the rate and the due day with your accountant.</p>
            @if ($settingsUrl)
                <p class="mt-2 text-sm"><a href="{{ $settingsUrl }}" class="text-primary-600 dark:text-primary-400">Change these in Settings → Payments → Gaming tax</a></p>
            @endif
        </x-filament::section>
    </div>
</x-filament-panels::page>
