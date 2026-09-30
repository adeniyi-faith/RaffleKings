@php
    $page = $getLivewire();
    $record = $page->getRecord();
    $tags = \App\Models\Admin\CustomerTag::query()->where('user_id', $record->ID)->orderBy('tag')->pluck('tag');
    $notes = \App\Models\Admin\CustomerNote::query()->with('author')->where('user_id', $record->ID)->orderByDesc('pinned')->latest('id')->limit(50)->get();
    $me = auth('wordpress')->id();
    $canManage = \App\Filament\Resources\Legacy\WpUserResource::staffCan('customers.manage');
@endphp
<div class="space-y-3">
    @if ($tags->isNotEmpty())
        <div class="flex flex-wrap gap-1.5">
            @foreach ($tags as $tag)
                <x-filament::badge color="primary">{{ $tag }}</x-filament::badge>
            @endforeach
        </div>
    @endif

    @foreach ($notes as $note)
        <div @class([
            'rounded-lg border p-3 text-sm',
            'border-warning-300 bg-warning-50 dark:border-warning-500/40 dark:bg-warning-500/10' => $note->pinned,
            'border-gray-200 dark:border-white/10' => ! $note->pinned,
        ])>
            <p class="whitespace-pre-line text-gray-950 dark:text-white">{{ $note->body }}</p>
            <div class="mt-2 flex flex-wrap items-center gap-3 text-xs text-gray-500 dark:text-gray-400">
                <span>{{ $note->author?->display_name ?: 'Staff' }} · {{ $note->created_at?->diffForHumans() }}</span>
                <button type="button" class="font-semibold text-primary-600" wire:click="togglePin({{ $note->id }})">{{ $note->pinned ? 'Unpin' : 'Pin' }}</button>
                @if ($note->author_id === $me || $canManage)
                    <button type="button" class="font-semibold text-danger-600" wire:click="deleteNote({{ $note->id }})" wire:confirm="Delete this note?">Delete</button>
                @endif
            </div>
        </div>
    @endforeach
</div>
