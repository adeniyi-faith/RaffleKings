@php
    $cell = 'padding:6px 8px;border-bottom:1px solid rgba(127,127,127,.15);text-align:right';
    $head = 'padding:6px 8px;text-align:right;font-size:11px;text-transform:uppercase;color:#9ca3af';
@endphp
<div style="font-size:14px">
    <p style="color:#6b7280;margin-bottom:12px">The last {{ $report['days'] }} days. A view is counted only when at least half the ad was on screen for a second.</p>

    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(120px,1fr));gap:8px;margin-bottom:16px">
        @foreach ([
            ['Views', number_format($report['total']['views'])],
            ['Taps', number_format($report['total']['clicks'])],
            ['Tap rate', $report['total']['rate'].'%'],
            ['Closed', number_format($report['total']['closes'])],
            ['People who saw it', number_format($report['people'])],
            ['People who tapped', number_format($report['tappers'])],
            ['Tapped, then bought a ticket within 7 days', number_format($report['bought_after'])],
        ] as [$label, $value])
            <div style="border:1px solid rgba(127,127,127,.2);border-radius:12px;padding:10px">
                <p style="font-size:11px;color:#9ca3af">{{ $label }}</p>
                <p style="font-size:20px;font-weight:700">{{ $value }}</p>
            </div>
        @endforeach
    </div>

    @foreach ([
        ['Versions (A/B test)', $report['variants'], 'label', 'Version'],
        ['Spots on the site', $report['placements'], 'label', 'Spot'],
        ['Day by day', $report['daily'], 'day', 'Day'],
    ] as [$title, $rows, $key, $labelHead])
        <p style="font-weight:600;margin:12px 0 4px">{{ $title }}</p>
        @if ($rows === [])
            <p style="color:#9ca3af">Nothing yet.</p>
        @else
            <div style="overflow-x:auto">
                <table style="width:100%;border-collapse:collapse">
                    <thead><tr><th style="{{ $head }};text-align:left">{{ $labelHead }}</th><th style="{{ $head }}">Views</th><th style="{{ $head }}">Taps</th><th style="{{ $head }}">Tap rate</th><th style="{{ $head }}">Closed</th></tr></thead>
                    <tbody>
                        @php $best = $key === 'label' && count($rows) > 1 ? collect($rows)->sortByDesc('rate')->first()['label'] : null; @endphp
                        @foreach ($rows as $row)
                            <tr>
                                <td style="{{ $cell }};text-align:left">{{ $row[$key] }} @if ($best && $row['label'] === $best && $title === 'Versions (A/B test)')<span style="font-size:11px;color:#16a34a;font-weight:700">· most taps per view</span>@endif</td>
                                <td style="{{ $cell }}">{{ number_format($row['views']) }}</td>
                                <td style="{{ $cell }}">{{ number_format($row['clicks']) }}</td>
                                <td style="{{ $cell }}">{{ $row['rate'] }}%</td>
                                <td style="{{ $cell }}">{{ number_format($row['closes']) }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    @endforeach
    @if (count($report['variants']) > 1)
        <p style="font-size:12px;color:#9ca3af;margin-top:8px">With fewer than a few hundred views per version, the difference may just be luck. Wait for more views before choosing a winner.</p>
    @endif
</div>
