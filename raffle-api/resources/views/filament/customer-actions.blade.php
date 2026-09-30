{{-- The Actions menu on a customer's profile: everything staff can do, grouped by purpose. --}}
<div class="grid gap-5" role="menu" aria-label="Customer actions">
    @forelse ($groups as $group)
        <section aria-labelledby="grp-{{ \Illuminate\Support\Str::slug($group['heading']) }}">
            <h3 id="grp-{{ \Illuminate\Support\Str::slug($group['heading']) }}" class="mb-1.5 flex items-center gap-2 text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">
                <x-filament::icon :icon="$group['icon']" class="h-4 w-4" />
                {{ $group['heading'] }}
            </h3>
            <div class="grid gap-1">
                @foreach ($group['items'] as $item)
                    <button
                        type="button"
                        role="menuitem"
                        wire:click="replaceMountedAction('{{ $item['name'] }}')"
                        class="flex w-full items-center gap-3 rounded-xl px-2 py-2 text-start transition hover:bg-gray-50 focus-visible:outline focus-visible:outline-2 focus-visible:outline-primary-600 dark:hover:bg-white/5"
                    >
                        <span class="flex h-9 w-9 flex-none items-center justify-center rounded-lg {{ $item['danger'] ? 'bg-danger-50 text-danger-600 dark:bg-danger-500/10 dark:text-danger-400' : 'bg-gray-100 text-gray-600 dark:bg-white/10 dark:text-gray-300' }}">
                            <x-filament::icon :icon="$item['icon']" class="h-5 w-5" />
                        </span>
                        <span class="min-w-0">
                            <span class="block text-sm font-medium {{ $item['danger'] ? 'text-danger-600 dark:text-danger-400' : 'text-gray-950 dark:text-white' }}">{{ $item['label'] }}</span>
                            @if ($item['help'] !== '')
                                <span class="block text-xs text-gray-500 dark:text-gray-400">{{ $item['help'] }}</span>
                            @endif
                        </span>
                    </button>
                @endforeach
            </div>
        </section>
    @empty
        <p class="text-sm text-gray-500 dark:text-gray-400">There is nothing you can do for this customer from here.</p>
    @endforelse
</div>
