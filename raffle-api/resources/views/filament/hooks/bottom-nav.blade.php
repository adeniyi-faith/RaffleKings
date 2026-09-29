{{-- Phone-only tab bar (see App\Filament\Support\MobileTabs). --}}
<nav class="rk-tabbar" aria-label="Quick links">
    @foreach (\App\Filament\Support\MobileTabs::items() as $tab)
        <a href="{{ $tab['url'] }}" wire:navigate @class(['rk-tab', 'is-active' => $tab['active']]) @if ($tab['active']) aria-current="page" @endif>
            <x-filament::icon :icon="$tab['icon']" class="rk-tab-icon" />
            <span>{{ $tab['label'] }}</span>
            @if (filled($tab['badge']))
                <span class="rk-tab-badge">{{ $tab['badge'] }}</span>
            @endif
        </a>
    @endforeach
    <button type="button" class="rk-tab" x-data x-on:click="$store.sidebar.open()">
        <x-filament::icon icon="heroicon-o-bars-3" class="rk-tab-icon" />
        <span>Menu</span>
    </button>
</nav>
