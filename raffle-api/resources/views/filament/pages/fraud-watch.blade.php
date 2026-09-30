@php
    $name = fn ($id) => $users[$id]?->display_name ?: $users[$id]?->user_login ?: "Customer #{$id}";
@endphp
<x-filament-panels::page>
    @if ($abuseNotice)
        <div class="rounded-xl border border-gray-200 bg-gray-50 px-4 py-3 text-sm text-gray-600 dark:border-white/10 dark:bg-white/5 dark:text-gray-300">
            <x-filament::badge color="gray" class="mr-1 inline-flex">Off</x-filament::badge>
            Multi-account protection: {{ $abuseNotice }}
        </div>
    @endif

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
            <p class="text-sm text-gray-500 dark:text-gray-400">Nothing found. Every bank account belongs to one customer.</p>
        @endforelse
    </x-filament::section>

    <x-filament::section icon="heroicon-o-bolt" icon-color="warning">
        <x-slot name="heading">Many top-ups in a short time ({{ $rapid->count() }})</x-slot>
        <x-slot name="description">{{ \App\Services\Risk\FraudWatchService::RAPID_TOPUPS }}+ successful top-ups within {{ \App\Services\Risk\FraudWatchService::RAPID_WINDOW_MINUTES }} minutes. This is how stolen cards are usually tested.</x-slot>

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
        <x-slot name="description">A withdrawal soon after topping up with little played, or from a brand-new account. Paying it out can turn a stolen card into clean cash.</x-slot>

        @forelse ($cashouts as $row)
            <a href="{{ $this->profileUrl($row['user_id']) }}" @class(['block py-3', 'border-t border-gray-100 dark:border-white/5' => ! $loop->first])>
                <span class="block font-semibold">{{ $name($row['user_id']) }} <span class="font-normal text-gray-500">· withdrawal #{{ $row['withdrawal_id'] }}</span></span>
                <span class="block text-sm text-gray-500 dark:text-gray-400">{{ $row['reason'] }}</span>
            </a>
        @empty
            <p class="text-sm text-gray-500 dark:text-gray-400">Nothing unusual in the last {{ $days }} days.</p>
        @endforelse
    </x-filament::section>
    @if ($heldReferrals->isNotEmpty() || $heldAffiliate->isNotEmpty())
        <x-filament::section icon="heroicon-o-pause-circle" icon-color="danger">
            <x-slot name="heading">Rewards held for a check ({{ $heldReferrals->count() + $heldAffiliate->count() }})</x-slot>
            <x-slot name="description">The customer who earned it and the friend they brought look like the same person. Pay it if they are genuinely different people (family members can share a phone); cancel it if not.</x-slot>

            @foreach ($heldReferrals as $row)
                <div @class(['flex flex-wrap items-center justify-between gap-3 py-3', 'border-t border-gray-100 dark:border-white/5' => ! $loop->first])>
                    <span class="min-w-0">
                        <span class="block font-semibold">Referral: <a class="underline" href="{{ $this->profileUrl($row->referrer_user_id) }}">{{ $name($row->referrer_user_id) }}</a> → <a class="underline" href="{{ $this->profileUrl($row->referee_user_id) }}">{{ $name($row->referee_user_id) }}</a> · ₦{{ number_format((float) $row->commission_amount) }}</span>
                        <span class="block text-sm text-gray-500 dark:text-gray-400">{{ $row->hold_reason }}</span>
                    </span>
                    <span class="flex gap-2">
                        <x-filament::button size="sm" color="success" wire:click="releaseReferral({{ $row->id }})" wire:confirm="Pay this referral commission?">Pay it</x-filament::button>
                        <x-filament::button size="sm" color="danger" wire:click="cancelReferral({{ $row->id }})" wire:confirm="Cancel this commission for good?">Cancel</x-filament::button>
                    </span>
                </div>
            @endforeach

            @foreach ($heldAffiliate as $row)
                <div class="flex flex-wrap items-center justify-between gap-3 border-t border-gray-100 py-3 dark:border-white/5">
                    <span class="min-w-0">
                        <span class="block font-semibold">Affiliate {{ $row->affiliate?->name }}: from <a class="underline" href="{{ $this->profileUrl($row->customer_id) }}">{{ $name($row->customer_id) }}</a> · ₦{{ number_format((float) $row->commission) }}</span>
                        <span class="block text-sm text-gray-500 dark:text-gray-400">{{ $row->note }}</span>
                    </span>
                    <span class="flex gap-2">
                        <x-filament::button size="sm" color="success" wire:click="releaseAffiliate({{ $row->id }})" wire:confirm="Approve this affiliate earning?">Approve</x-filament::button>
                        <x-filament::button size="sm" color="danger" wire:click="cancelAffiliate({{ $row->id }})" wire:confirm="Cancel this earning for good?">Cancel</x-filament::button>
                    </span>
                </div>
            @endforeach
        </x-filament::section>
    @endif

    @if ($abuseOn)
        @foreach ([['Same phone number on several customers', 'heroicon-o-device-phone-mobile', $phones, 'Phone ending'], ['Same phone or computer used by several customers', 'heroicon-o-computer-desktop', $devices, 'Browser']] as [$heading, $icon, $groups, $label])
            <x-filament::section :icon="$icon" icon-color="warning">
                <x-slot name="heading">{{ $heading }} ({{ $groups->count() }})</x-slot>
                <x-slot name="description">Often one person running several accounts for sign-up and referral bonuses. Families and shared phones are real too, so look before acting.</x-slot>

                @forelse ($groups as $group)
                    <div @class(['py-3', 'border-t border-gray-100 dark:border-white/5' => ! $loop->first])>
                        <p class="text-sm text-gray-500">{{ $label }} {{ $group['key'] }}</p>
                        <div class="mt-2 flex flex-wrap gap-2">
                            @foreach ($group['user_ids'] as $id)
                                <x-filament::badge :href="$this->profileUrl($id)" tag="a" color="warning">{{ $name($id) }}</x-filament::badge>
                            @endforeach
                        </div>
                    </div>
                @empty
                    <p class="text-sm text-gray-500 dark:text-gray-400">Nothing found.</p>
                @endforelse
            </x-filament::section>
        @endforeach
    @endif
</x-filament-panels::page>
