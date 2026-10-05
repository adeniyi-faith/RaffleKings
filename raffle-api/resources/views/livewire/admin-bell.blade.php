{{-- Admin top-bar bell (App\Livewire\AdminBell). Plain CSS, like filament/hooks/head. --}}
<div class="rk-bell" wire:poll.60s x-data="{ open: false }" x-on:keydown.escape.window="open = false" x-on:click.outside="open = false">
    @if ($me)
        <button type="button" class="rk-bell-btn" x-on:click="open = ! open" x-bind:aria-expanded="open"
                aria-label="Team to-do{{ $count ? ", {$count} waiting" : '' }}">
            <x-filament::icon icon="heroicon-o-bell" class="rk-bell-icon" />
            @if ($count > 0)
                <span class="rk-bell-badge">{{ $count > 9 ? '9+' : $count }}</span>
            @endif
        </button>

        <div class="rk-bell-panel" x-show="open" x-transition.opacity.duration.150ms x-cloak role="dialog" aria-label="Team to-do">
            <div class="rk-bell-head">
                <span class="rk-bell-title">Team to-do</span>
                <span class="rk-bell-sub">{{ $count === 0 ? 'All clear' : $count.' waiting' }}</span>
            </div>

            <ul class="rk-bell-list">
                @forelse ($open as $task)
                    <li class="rk-bell-item" wire:key="open-{{ $task->id }}">
                        <button type="button" class="rk-bell-check" wire:click="tick({{ $task->id }})" wire:loading.attr="disabled"
                                title="Mark done" aria-label="Mark done: {{ $task->title }}"></button>
                        <a class="rk-bell-text" href="{{ \App\Services\Admin\StaffTodo::url($task) ?? $allUrl }}" wire:navigate x-on:click="open = false">
                            <span class="rk-bell-line">{{ $task->title }}</span>
                            <span class="rk-bell-meta">
                                <x-filament::icon :icon="\App\Services\Admin\StaffTodo::icon($task)" class="rk-bell-src" />
                                {{ \App\Services\Admin\StaffTodo::label($task) }} · waiting {{ ($task->waiting_since ?? $task->created_at)->diffForHumans(null, true) }}
                            </span>
                        </a>
                    </li>
                @empty
                    <li class="rk-bell-empty">
                        <x-filament::icon icon="heroicon-o-check-badge" class="rk-bell-empty-icon" />
                        Nothing is waiting for the team.
                    </li>
                @endforelse
            </ul>

            @if ($done->isNotEmpty())
                <div class="rk-bell-donehead">Recently done</div>
                <ul class="rk-bell-list">
                    @foreach ($done as $task)
                        <li class="rk-bell-item is-done" wire:key="done-{{ $task->id }}">
                            <span class="rk-bell-check is-checked" aria-hidden="true">
                                <x-filament::icon icon="heroicon-m-check" class="rk-bell-tick" />
                            </span>
                            <span class="rk-bell-text">
                                <span class="rk-bell-line">{{ $task->title }}</span>
                                <span class="rk-bell-meta">
                                    {{ $task->done_by === $me ? 'You' : $task->doneByLabel() }}, {{ $task->done_at->diffForHumans() }}
                                    @if ($task->done_by && ! $task->cleared_at)
                                        · <button type="button" class="rk-bell-undo" wire:click="undo({{ $task->id }})">Undo</button>
                                    @endif
                                </span>
                            </span>
                        </li>
                    @endforeach
                </ul>
            @endif

            <a class="rk-bell-all" href="{{ $allUrl }}" wire:navigate x-on:click="open = false">See the full list</a>
        </div>
    @endif

    <style>
        .rk-bell { position: relative; display: flex; align-items: center; }
        .rk-bell-btn {
            position: relative; display: flex; align-items: center; justify-content: center;
            width: 36px; height: 36px; border-radius: 9999px; color: rgb(var(--gray-500));
            -webkit-tap-highlight-color: transparent; transition: transform .1s, background-color .15s;
        }
        .rk-bell-btn:hover { background: rgb(var(--gray-100)); }
        .rk-bell-btn:active { transform: scale(.9); }
        .dark .rk-bell-btn { color: rgb(var(--gray-300)); }
        .dark .rk-bell-btn:hover { background: rgb(255 255 255 / .06); }
        .rk-bell-icon { width: 22px; height: 22px; }
        /* Same red number as the customer site's bell. */
        .rk-bell-badge {
            position: absolute; top: 3px; right: 2px;
            min-width: 16px; height: 16px; padding: 0 4px; border-radius: 9999px;
            display: flex; align-items: center; justify-content: center;
            background: #ef4444; color: #fff; font-size: 9px; font-weight: 700; line-height: 1;
            box-shadow: 0 0 0 2px #fff;
        }
        .dark .rk-bell-badge { box-shadow: 0 0 0 2px rgb(var(--gray-900)); }

        .rk-bell-panel {
            position: absolute; top: calc(100% + 8px); right: 0; z-index: 40;
            width: 380px; max-height: min(70vh, 560px); overflow-y: auto; overscroll-behavior: contain;
            border-radius: 12px; background: #fff;
            box-shadow: 0 10px 30px rgb(0 0 0 / .12), 0 0 0 1px rgba(var(--gray-950), .06);
        }
        .dark .rk-bell-panel { background: rgb(var(--gray-900)); box-shadow: 0 10px 30px rgb(0 0 0 / .4), 0 0 0 1px rgb(255 255 255 / .1); }
        @media (max-width: 639px) {
            .rk-bell-panel { position: fixed; top: 60px; left: 12px; right: 12px; width: auto; }
        }
        .rk-bell-head { display: flex; align-items: baseline; justify-content: space-between; padding: 14px 16px 8px; }
        .rk-bell-title { font-size: 15px; font-weight: 600; color: rgb(var(--gray-950)); }
        .rk-bell-sub { font-size: 12px; color: rgb(var(--gray-500)); }
        .dark .rk-bell-title { color: #fff; }
        .rk-bell-list { margin: 0; padding: 0 6px; list-style: none; }
        .rk-bell-item { display: flex; align-items: flex-start; gap: 10px; padding: 8px 10px; border-radius: 8px; }
        .rk-bell-item:hover { background: rgb(var(--gray-50)); }
        .dark .rk-bell-item:hover { background: rgb(255 255 255 / .04); }
        .rk-bell-check {
            flex: none; width: 20px; height: 20px; margin-top: 1px; border-radius: 6px;
            display: flex; align-items: center; justify-content: center;
            border: 2px solid rgb(var(--gray-300)); background: transparent; transition: border-color .15s, background-color .15s;
        }
        button.rk-bell-check:hover { border-color: rgb(var(--success-500)); background: rgba(var(--success-500), .1); }
        .rk-bell-check.is-checked { border-color: rgb(var(--success-500)); background: rgb(var(--success-500)); color: #fff; }
        .dark .rk-bell-check { border-color: rgb(var(--gray-600)); }
        .rk-bell-tick { width: 14px; height: 14px; }
        .rk-bell-text { min-width: 0; flex: 1 1 auto; display: flex; flex-direction: column; gap: 2px; }
        .rk-bell-line { font-size: 13px; line-height: 18px; color: rgb(var(--gray-900)); overflow-wrap: anywhere; }
        .dark .rk-bell-line { color: rgb(var(--gray-100)); }
        .is-done .rk-bell-line { color: rgb(var(--gray-500)); text-decoration: line-through; }
        .rk-bell-meta { display: flex; flex-wrap: wrap; align-items: center; gap: 4px; font-size: 11px; color: rgb(var(--gray-500)); }
        .rk-bell-src { width: 13px; height: 13px; }
        .rk-bell-undo { color: rgb(var(--primary-600)); font-weight: 600; }
        .rk-bell-donehead { padding: 10px 16px 2px; font-size: 11px; font-weight: 600; text-transform: uppercase; letter-spacing: .04em; color: rgb(var(--gray-400)); }
        .rk-bell-empty { display: flex; flex-direction: column; align-items: center; gap: 6px; padding: 20px 10px; font-size: 13px; color: rgb(var(--gray-500)); }
        .rk-bell-empty-icon { width: 28px; height: 28px; color: rgb(var(--success-500)); }
        .rk-bell-all {
            display: block; margin: 8px 6px 6px; padding: 10px; border-radius: 8px; text-align: center;
            font-size: 13px; font-weight: 600; color: rgb(var(--primary-600));
        }
        .rk-bell-all:hover { background: rgb(var(--gray-50)); }
        .dark .rk-bell-all { color: rgb(var(--primary-400)); }
        .dark .rk-bell-all:hover { background: rgb(255 255 255 / .04); }
    </style>
</div>
