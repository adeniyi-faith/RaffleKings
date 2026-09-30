@php
    $badges = collect($getState() ?? []);
    $earned = $badges->filter(fn ($b) => $b['earned_at'])->values();
    $locked = $badges->reject(fn ($b) => $b['earned_at'])->values();
@endphp

<div class="space-y-3">
    @if ($earned->isEmpty())
        <p class="text-sm text-gray-500 dark:text-gray-400">No badges earned yet.</p>
    @else
        <ul class="grid gap-2 sm:grid-cols-2 xl:grid-cols-3">
            @foreach ($earned as $badge)
                <li class="flex items-center gap-3 rounded-xl border border-gray-200 bg-white px-3 py-2 dark:border-white/10 dark:bg-white/5">
                    <span class="text-2xl leading-none" aria-hidden="true">{{ $badge['emoji'] }}</span>
                    <span class="min-w-0">
                        <span class="block truncate text-sm font-semibold text-gray-950 dark:text-white">
                            {{ $badge['name'] }}
                            @if ($badge['pinned'])
                                <span class="ml-1 text-xs font-medium text-primary-600 dark:text-primary-400" title="Pinned to their profile">📌 pinned</span>
                            @endif
                        </span>
                        <span class="block text-xs text-gray-500 dark:text-gray-400">
                            Earned {{ \Illuminate\Support\Carbon::parse($badge['earned_at'])->setTimezone(config('raffles.timezone'))->format('j M Y') }}
                        </span>
                    </span>
                </li>
            @endforeach
        </ul>
    @endif

    @if ($locked->isNotEmpty())
        <details class="text-sm text-gray-500 dark:text-gray-400">
            <summary class="cursor-pointer select-none">Not earned yet ({{ $locked->count() }})</summary>
            <ul class="mt-2 grid gap-1 sm:grid-cols-2">
                @foreach ($locked as $badge)
                    <li class="flex items-center gap-2 opacity-70">
                        <span aria-hidden="true">{{ $badge['emoji'] }}</span>
                        <span>{{ $badge['name'] }} <span class="text-xs">· {{ $badge['description'] }}</span></span>
                    </li>
                @endforeach
            </ul>
        </details>
    @endif
</div>
