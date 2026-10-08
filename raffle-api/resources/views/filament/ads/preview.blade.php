@php
    // Roughly the site's colours (resources/js/Components/home/homeTheme.jsx), for the preview only.
    $colours = [
        'blue' => ['#1d4ed8', '#4338ca'], 'green' => ['#16a34a', '#047857'], 'red' => ['#b91c1c', '#dc2626'],
        'purple' => ['#7e22ce', '#a21caf'], 'orange' => ['#ea580c', '#b45309'], 'gold' => ['#111827', '#000000'], 'gray' => ['#374151', '#111827'],
    ];
    [$from, $to] = $colours[$variant['theme']] ?? $colours['green'];
    $title = $variant['title'] ?: 'Your headline';
@endphp
<div style="font-family:inherit">
    <p style="font-size:12px;color:#9ca3af;margin-bottom:8px">How version A looks on a phone</p>
    @if ($look === 'banner')
        <div style="border-radius:16px;overflow:hidden;color:#fff;padding:18px;min-height:120px;background:{{ $variant['image'] ? 'linear-gradient(rgba(0,0,0,.45),rgba(0,0,0,.45)), url('.e($variant['image']).') center/cover' : "linear-gradient(135deg,$from,$to)" }}">
            @if ($variant['badge'])<span style="font-size:10px;font-weight:800;text-transform:uppercase;background:rgba(255,255,255,.2);padding:2px 6px;border-radius:4px">{{ $variant['badge'] }}</span>@endif
            <p style="font-size:20px;font-weight:800;line-height:1.2;margin-top:6px">{{ $title }}</p>
            @if ($variant['text'])<p style="font-size:12px;opacity:.85;margin-top:4px">{{ $variant['text'] }}</p>@endif
            <span style="display:inline-block;margin-top:10px;background:#fff;color:{{ $from }};font-weight:700;font-size:13px;padding:8px 18px;border-radius:999px">{{ $variant['button'] }}</span>
        </div>
    @elseif ($look === 'strip')
        <div style="display:flex;align-items:center;gap:10px;border-radius:12px;background:#fff;border:1px solid #e5e7eb;padding:10px 12px;color:#111827">
            <span style="width:28px;height:28px;border-radius:999px;background:{{ $from }}22;color:{{ $from }};display:inline-flex;align-items:center;justify-content:center;font-weight:800">★</span>
            <p style="flex:1;font-size:13px;font-weight:600">{{ $title }} @if ($variant['text'])<span style="font-weight:400;color:#6b7280">· {{ $variant['text'] }}</span>@endif</p>
            <span style="color:{{ $from }};font-weight:800">›</span>
        </div>
    @else
        <div style="display:flex;align-items:center;gap:12px;border-radius:16px;background:linear-gradient(135deg,{{ $from }}14,{{ $to }}0d);border:1px solid {{ $from }}33;padding:14px;color:#111827">
            @if ($variant['image'])
                <img src="{{ $variant['image'] }}" alt="" style="width:52px;height:52px;border-radius:12px;object-fit:cover">
            @else
                <span style="width:52px;height:52px;border-radius:12px;background:{{ $from }}22;color:{{ $from }};display:inline-flex;align-items:center;justify-content:center;font-size:22px">★</span>
            @endif
            <div style="flex:1;min-width:0">
                @if ($variant['badge'])<span style="font-size:10px;font-weight:800;color:{{ $from }}">{{ $variant['badge'] }}</span>@endif
                <p style="font-size:15px;font-weight:700;line-height:1.25">{{ $title }}</p>
                @if ($variant['text'])<p style="font-size:12px;color:#6b7280;margin-top:2px">{{ $variant['text'] }}</p>@endif
            </div>
            <span style="background:{{ $from }};color:#fff;font-weight:700;font-size:13px;padding:8px 16px;border-radius:999px;white-space:nowrap">{{ $variant['button'] }}</span>
        </div>
    @endif
    <p style="font-size:11px;color:#9ca3af;margin-top:8px">The pop-up and homepage slide use their own bigger look. A new picture shows here after saving.</p>
</div>
