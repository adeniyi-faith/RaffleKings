<div class="space-y-6 text-sm">
    <div>
        <h3 class="mb-2 font-semibold">Sign-ins to the admin</h3>
        @forelse ($signIns as $row)
            <div class="flex items-start justify-between gap-3 border-b border-gray-100 py-2 last:border-0 dark:border-white/10">
                <div>
                    <p class="{{ $row['ok'] ? '' : 'text-danger-600' }}">
                        {{ $row['ok'] ? 'Signed in' : 'Refused: '.$row['why'] }}
                        @if ($row['new_place'])
                            <span class="ml-1 rounded px-1.5 py-0.5 text-xs" style="background:rgba(var(--warning-500),.15);color:rgb(var(--warning-700))">new place</span>
                        @endif
                    </p>
                    <p class="text-xs text-gray-500 dark:text-gray-400">{{ $row['device'] }} · {{ $row['ip'] ?? 'unknown place' }}</p>
                </div>
                <p class="shrink-0 text-xs text-gray-500 dark:text-gray-400">{{ $row['when']->timezone(config('raffles.timezone'))->format('j M, H:i') }}</p>
            </div>
        @empty
            <p class="text-gray-500">No sign-ins recorded yet.</p>
        @endforelse
    </div>

    <div>
        <h3 class="mb-2 font-semibold">What they changed</h3>
        @forelse ($actions as $log)
            <div class="flex items-start justify-between gap-3 border-b border-gray-100 py-2 last:border-0 dark:border-white/10">
                <div>
                    <p>{{ $describe($log->action) }}</p>
                    @if ($details($log) !== '')
                        <p class="text-xs text-gray-500 dark:text-gray-400" style="word-break:break-word">{{ \Illuminate\Support\Str::limit($details($log), 160) }}</p>
                    @endif
                </div>
                <p class="shrink-0 text-xs text-gray-500 dark:text-gray-400">{{ $log->created_at?->timezone(config('raffles.timezone'))->format('j M, H:i') }}</p>
            </div>
        @empty
            <p class="text-gray-500">Nothing changed yet.</p>
        @endforelse
    </div>
</div>
