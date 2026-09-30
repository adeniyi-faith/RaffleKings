@php
    $page = $getLivewire();
    $events = $page->timelineEvents();
    $colors = [
        'success' => 'text-success-600 dark:text-success-400',
        'warning' => 'text-warning-600 dark:text-warning-400',
        'danger' => 'text-danger-600 dark:text-danger-400',
        'info' => 'text-info-600 dark:text-info-400',
        'primary' => 'text-primary-600 dark:text-primary-400',
        'gray' => 'text-gray-500 dark:text-gray-400',
    ];
    $tz = config('raffles.timezone');
@endphp
<div>
    <div class="mb-4 flex flex-wrap gap-2">
        <x-filament::button size="xs" :color="$page->timelineKind === '' ? 'primary' : 'gray'" wire:click="$set('timelineKind', '')">Everything</x-filament::button>
        @foreach (\App\Services\Admin\CustomerTimeline::KINDS as $key => $label)
            <x-filament::button size="xs" :color="$page->timelineKind === $key ? 'primary' : 'gray'" wire:click="$set('timelineKind', '{{ $key }}')">{{ $label }}</x-filament::button>
        @endforeach
    </div>

    @forelse ($events as $event)
        @php $day = $event['at']->copy()->setTimezone($tz); @endphp
        @if ($loop->first || ! $day->isSameDay($events[$loop->index - 1]['at']->copy()->setTimezone($tz)))
            <p @class(['mb-2 text-xs font-semibold uppercase tracking-wide text-gray-400', 'mt-5' => ! $loop->first])>{{ $day->isToday() ? 'Today' : ($day->isYesterday() ? 'Yesterday' : $day->format('D j M Y')) }}</p>
        @endif
        <div class="flex items-start gap-3 border-b border-gray-100 py-2.5 last:border-0 dark:border-white/5">
            <x-filament::icon :icon="$event['icon']" @class(['mt-0.5 h-5 w-5 flex-shrink-0', $colors[$event['color']] ?? $colors['gray']]) />
            <div class="min-w-0 flex-1">
                <p class="text-sm font-medium text-gray-950 dark:text-white">{{ $event['title'] }}</p>
                @if ($event['detail'])
                    <p class="break-words text-xs text-gray-500 dark:text-gray-400">{{ $event['detail'] }}</p>
                @endif
            </div>
            <div class="flex-shrink-0 text-right">
                @if ($event['amount'])
                    <p class="text-sm font-semibold tabular-nums text-gray-950 dark:text-white">{{ $event['amount'] }}</p>
                @endif
                <p class="text-xs text-gray-400">{{ $day->format('H:i') }}</p>
            </div>
        </div>
    @empty
        <p class="text-sm text-gray-500 dark:text-gray-400">Nothing here yet.</p>
    @endforelse

    @if (count($events) >= $page->timelineLimit)
        <div class="mt-4">
            <x-filament::button size="sm" color="gray" wire:click="moreTimeline">Show older</x-filament::button>
        </div>
    @endif
</div>
