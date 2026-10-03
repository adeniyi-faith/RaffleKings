{{-- Screenshots the customer attached to this message (signed, short-lived links). --}}
<div class="flex flex-wrap gap-2">
    @foreach ($getRecord()->attachment_urls as $i => $url)
        <a href="{{ $url }}" target="_blank" rel="noopener" class="block">
            <img src="{{ $url }}" alt="Screenshot {{ $i + 1 }}" class="h-28 w-28 rounded-lg border border-gray-200 object-cover dark:border-gray-700" loading="lazy">
        </a>
    @endforeach
</div>
