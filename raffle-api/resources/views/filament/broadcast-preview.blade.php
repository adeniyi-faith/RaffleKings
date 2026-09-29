<div class="rounded-2xl border border-gray-200 bg-white p-4 shadow-sm dark:border-white/10 dark:bg-gray-900">
    <p class="text-xs font-medium text-gray-400">How it looks in their inbox</p>
    <p class="mt-2 font-semibold text-gray-900 dark:text-white" style="overflow-wrap:anywhere">{{ $title ?: 'Your headline' }}</p>
    <p class="mt-1 text-sm text-gray-600 dark:text-gray-300" style="white-space:pre-line;overflow-wrap:anywhere">{{ $body ?: 'Your message…' }}</p>
    @if ($label)
        <span class="mt-3 inline-block rounded-lg px-3 py-1.5 text-sm font-semibold text-white" style="background:rgb(var(--primary-600))">{{ $label }}</span>
    @endif
</div>
