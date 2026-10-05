<x-filament-panels::page>
    <x-filament::tabs label="Which to-dos">
        <x-filament::tabs.item :active="$show === 'open'" wire:click="$set('show', 'open')" icon="heroicon-o-bell-alert">
            Still to do
        </x-filament::tabs.item>
        <x-filament::tabs.item :active="$show === 'done'" wire:click="$set('show', 'done')" icon="heroicon-o-check-circle">
            Done
        </x-filament::tabs.item>
        <x-filament::tabs.item :active="$show === 'all'" wire:click="$set('show', 'all')" icon="heroicon-o-list-bullet">
            Everything
        </x-filament::tabs.item>
    </x-filament::tabs>

    {{ $this->table }}
</x-filament-panels::page>
