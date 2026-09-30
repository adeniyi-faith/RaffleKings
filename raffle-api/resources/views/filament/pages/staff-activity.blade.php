<x-filament-panels::page>
    <div class="grid grid-cols-1 gap-4 lg:grid-cols-3">
        <div class="rounded-xl bg-white p-4 shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10">
            <p class="text-xs font-medium text-gray-500 dark:text-gray-400">Two-step sign-in</p>
            <p class="mt-1 text-lg font-semibold {{ $twoStep ? 'text-success-600' : 'text-warning-600' }}">{{ $twoStep ? 'On' : 'Off' }}</p>
            <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">
                {{ $twoStep ? 'Staff must type a code we email them after their password.' : 'Staff sign in with just a password. Turn it on (button at the top) for an emailed code as well.' }}
            </p>
        </div>

        <div class="rounded-xl bg-white p-4 shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10">
            <p class="text-xs font-medium text-gray-500 dark:text-gray-400">Failed staff sign-ins, last 24 hours</p>
            @if ($alerts['failed'])
                <p class="mt-1 text-lg font-semibold text-danger-600">{{ $alerts['failed']['count'] }} tries from {{ $alerts['failed']['places'] }} {{ $alerts['failed']['places'] === 1 ? 'place' : 'places' }}</p>
                <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">Accounts tried: {{ implode(', ', $alerts['failed']['who']) }}. Someone may be guessing passwords.</p>
            @else
                <p class="mt-1 text-lg font-semibold text-success-600">Nothing unusual</p>
                <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">It is flagged from {{ \App\Services\Admin\StaffActivity::FAILED_ALERT_AT }} failed tries in a day.</p>
            @endif
        </div>

        <div class="rounded-xl bg-white p-4 shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10">
            <p class="text-xs font-medium text-gray-500 dark:text-gray-400">Sign-ins from a new place, last 7 days</p>
            @if ($alerts['new_places'] !== [])
                <p class="mt-1 text-lg font-semibold text-warning-600">{{ count($alerts['new_places']) }}</p>
                <ul class="mt-1 space-y-0.5 text-xs text-gray-500 dark:text-gray-400">
                    @foreach (array_slice($alerts['new_places'], 0, 3) as $n)
                        <li>{{ $n['name'] }}: {{ $n['device'] }}, {{ $n['ip'] ?? 'unknown place' }}, {{ $n['when']->diffForHumans() }}</li>
                    @endforeach
                </ul>
            @else
                <p class="mt-1 text-lg font-semibold text-success-600">None</p>
                <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">A "new place" is an internet address that person has never signed in from before.</p>
            @endif
        </div>
    </div>

    {{ $this->table }}
</x-filament-panels::page>
