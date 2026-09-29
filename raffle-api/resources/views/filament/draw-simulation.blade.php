{{-- Raffle Rules Engine simulator: the draw run many times with random seeds against today's tickets. Nothing is saved. --}}
<div class="space-y-4 text-sm">
    <p class="text-gray-600 dark:text-gray-300">
        The draw was run <strong>{{ number_format($result['runs']) }}</strong> times with random seeds on today's
        {{ number_format($result['pool_size']) }} entries from {{ number_format($result['players']) }} players
        ({{ $result['prizes'] }} {{ $result['prizes'] === 1 ? 'prize' : 'prizes' }}). Nothing was saved and the real draw is unaffected.
    </p>

    <div>
        <p class="mb-1 font-semibold">Rules used</p>
        <ul class="space-y-0.5 text-gray-600 dark:text-gray-300" style="list-style: disc; padding-left: 1.25rem;">
            @foreach ($rules as $line)
                <li>{{ $line }}</li>
            @endforeach
        </ul>
    </div>

    <div class="overflow-x-auto">
        <p class="mb-1 font-semibold">By loyalty tier</p>
        <table class="w-full text-left">
            <thead><tr class="text-gray-500"><th class="py-1 pr-3">Tier</th><th class="pr-3">Players</th><th class="pr-3">Avg entries</th><th>Chance to win a prize</th></tr></thead>
            <tbody>
                @forelse ($result['by_tier'] as $row)
                    <tr class="border-t border-gray-100 dark:border-white/10"><td class="py-1 pr-3">{{ $row['tier'] }}</td><td class="pr-3">{{ $row['players'] }}</td><td class="pr-3">{{ $row['avg_entries'] }}</td><td>{{ $row['chance_to_win_any'] }}%</td></tr>
                @empty
                    <tr><td colspan="4" class="py-1 text-gray-500">No eligible players.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <div class="overflow-x-auto">
        <p class="mb-1 font-semibold">Most likely winners</p>
        <table class="w-full text-left">
            <thead><tr class="text-gray-500"><th class="py-1 pr-3">Player</th><th class="pr-3">Tier</th><th class="pr-3">Entries</th><th class="pr-3">Any prize</th><th>Top prize</th></tr></thead>
            <tbody>
                @foreach ($result['top_players'] as $row)
                    <tr class="border-t border-gray-100 dark:border-white/10"><td class="py-1 pr-3">{{ $row['name'] }}</td><td class="pr-3">{{ $row['tier'] }}</td><td class="pr-3">{{ $row['entries'] }}</td><td class="pr-3">{{ $row['chance_to_win_any'] }}%</td><td>{{ $row['chance_top_prize'] }}%</td></tr>
                @endforeach
            </tbody>
        </table>
    </div>
</div>
