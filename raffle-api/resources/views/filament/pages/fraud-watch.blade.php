@php
    $name = fn ($id) => $users[$id]?->display_name ?: $users[$id]?->user_login ?: "Customer #{$id}";
@endphp
<x-filament-panels::page>
    <div class="flex flex-wrap items-center gap-2 text-sm">
        <span class="text-gray-500 dark:text-gray-400">Look back over</span>
        @foreach ([7 => '7 days', 30 => '30 days', 90 => '90 days'] as $value => $label)
            <x-filament::button size="sm" :color="$days === $value ? 'primary' : 'gray'" wire:click="$set('days', {{ $value }})">{{ $label }}</x-filament::button>
        @endforeach
    </div>

    <x-filament::section icon="heroicon-o-building-library" icon-color="danger">
        <x-slot name="heading">Same bank account on several customers ({{ $shared->count() }})</x-slot>
        <x-slot name="description">One person running many accounts to collect sign-up bonuses and referral commission, or one account collecting several people's winnings.</x-slot>

        @forelse ($shared as $group)
            <div @class(['py-3', 'border-t border-gray-100 dark:border-white/5' => ! $loop->first])>
                <p class="font-mono text-sm font-semibold">{{ $group['account_number'] }} <span class="font-sans font-normal text-gray-500">· {{ $group['bank_name'] }}</span></p>
                <div class="mt-2 flex flex-wrap gap-2">
                    @foreach ($group['user_ids'] as $id)
                        <x-filament::badge :href="$this->profileUrl($id)" tag="a" color="danger">{{ $name($id) }}</x-filament::badge>
                    @endforeach
                </div>
            </div>
        @empty
            <p class="text-sm text-gray-500 dark:text-gray-400">Nothing found — every bank account belongs to one customer.</p>
        @endforelse
    </x-filament::section>

    <x-filament::section icon="heroicon-o-bolt" icon-color="warning">
        <x-slot name="heading">Many top-ups in a short time ({{ $rapid->count() }})</x-slot>
        <x-slot name="description">{{ \App\Services\Risk\FraudWatchService::RAPID_TOPUPS }}+ successful top-ups within {{ \App\Services\Risk\FraudWatchService::RAPID_WINDOW_MINUTES }} minutes — how stolen cards are usually tested.</x-slot>

        @forelse ($rapid as $row)
            <a href="{{ $this->profileUrl($row['user_id']) }}" @class(['flex items-center justify-between gap-3 py-3', 'border-t border-gray-100 dark:border-white/5' => ! $loop->first])>
                <span>
                    <span class="block font-semibold">{{ $name($row['user_id']) }}</span>
                    <span class="block text-sm text-gray-500 dark:text-gray-400">{{ $row['count'] }} top-ups from {{ $row['first_at']->format('j M, H:i') }}</span>
                </span>
                <span class="font-semibold tabular-nums">₦{{ number_format($row['total']) }}</span>
            </a>
        @empty
            <p class="text-sm text-gray-500 dark:text-gray-400">Nothing unusual in the last {{ $days }} days.</p>
        @endforelse
    </x-filament::section>

    <x-filament::section icon="heroicon-o-arrow-up-tray" icon-color="warning">
        <x-slot name="heading">Quick cash-outs ({{ $cashouts->count() }})</x-slot>
        <x-slot name="description">A withdrawal soon after topping up with little played, or from a brand-new account — paying it out can turn a stolen card into clean cash.</x-slot>

        @forelse ($cashouts as $row)
            <a href="{{ $this->profileUrl($row['user_id']) }}" @class(['block py-3', 'border-t border-gray-100 dark:border-white/5' => ! $loop->first])>
                <span class="block font-semibold">{{ $name($row['user_id']) }} <span class="font-normal text-gray-500">· withdrawal #{{ $row['withdrawal_id'] }}</span></span>
                <span class="block text-sm text-gray-500 dark:text-gray-400">{{ $row['reason'] }}</span>
            </a>
        @empty
            <p class="text-sm text-gray-500 dark:text-gray-400">Nothing unusual in the last {{ $days }} days.</p>
        @endforelse
    </x-filament::section>
</x-filament-panels::page>
