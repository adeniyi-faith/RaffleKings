@php($card = $getState() ?? [])
<div class="rk-card">
    @if (filled($card['title'] ?? null) || filled($card['amount'] ?? null))
        <div class="rk-card-top">
            <span class="rk-card-who">
                @if (filled($card['avatar'] ?? null))
                    <img
                        src="{{ $card['avatar'] }}"
                        alt=""
                        class="rk-card-avatar"
                        loading="lazy"
                        @if (filled($card['avatar_fallback'] ?? null))
                            onerror="this.onerror=null;this.src='{{ $card['avatar_fallback'] }}'"
                        @endif
                    >
                @endif
                <span class="rk-card-title">{{ $card['title'] ?? '' }}</span>
            </span>
            @if (filled($card['amount'] ?? null))
                <span class="rk-card-amount">{{ $card['amount'] }}</span>
            @endif
        </div>
    @endif

    @if (filled($card['body'] ?? null))
        <p class="rk-card-body">{{ $card['body'] }}</p>
    @endif

    @foreach (array_filter($card['lines'] ?? []) as $line)
        <p class="rk-card-line">{{ $line }}</p>
    @endforeach

    @if (filled($card['copy']['value'] ?? null))
        <button
            type="button"
            class="rk-copy"
            x-data
            x-on:click.stop.prevent="window.navigator.clipboard.writeText(@js((string) $card['copy']['value'])); $tooltip('Copied', { theme: $store.theme, timeout: 1500 })"
        >
            <span>{{ $card['copy']['label'] ?? $card['copy']['value'] }}</span>
            <svg viewBox="0 0 20 20" fill="currentColor" aria-hidden="true"><path d="M7 3.5A1.5 1.5 0 0 1 8.5 2h3.879a1.5 1.5 0 0 1 1.06.44l3.122 3.12A1.5 1.5 0 0 1 17 6.622V12.5a1.5 1.5 0 0 1-1.5 1.5h-1v-3.379a3 3 0 0 0-.879-2.121L10.5 5.379A3 3 0 0 0 8.379 4.5H7v-1Z"/><path d="M4.5 6A1.5 1.5 0 0 0 3 7.5v9A1.5 1.5 0 0 0 4.5 18h7a1.5 1.5 0 0 0 1.5-1.5v-5.879a1.5 1.5 0 0 0-.44-1.06L9.44 6.439A1.5 1.5 0 0 0 8.378 6H4.5Z"/></svg>
            <span class="sr-only">Copy</span>
        </button>
    @endif

    @if (filled($card['badges'] ?? null) || filled($card['meta'] ?? null))
        <div class="rk-card-meta">
            @foreach ($card['badges'] ?? [] as [$text, $color])
                @if (filled($text))
                    <span class="rk-chip rk-chip-{{ $color }}">{{ $text }}</span>
                @endif
            @endforeach
            @if (filled($card['meta'] ?? null))
                <span>{{ $card['meta'] }}</span>
            @endif
        </div>
    @endif
</div>
