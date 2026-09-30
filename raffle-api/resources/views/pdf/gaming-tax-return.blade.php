@php
    $naira = fn ($n) => ($n < 0 ? '-' : '').'₦'.number_format(abs((float) $n), 2);
    $rate = rtrim(rtrim(number_format((float) $s['rate'], 3), '0'), '.');
    $rec = $s['record'];
    $tz = config('raffles.timezone');
    $month = \Illuminate\Support\Carbon::createFromFormat('!Y-m', $s['period'], $tz);
    $ruleText = $s['shortfall_rule'] === 'carry_forward'
        ? 'A shortfall (prizes worth more than sales) is carried into the next month.'
        : 'A shortfall (prizes worth more than sales) is not carried forward; that month owes no tax.';
    $statusText = ['open' => 'Draft (not locked)', 'locked' => 'Locked', 'filed' => 'Filed', 'paid' => 'Paid'][$s['status']];
@endphp
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>Gaming tax return {{ $s['period'] }}</title>
<style>
    @page { margin: 28px 40px 52px 40px; }
    body { font-family: "DejaVu Sans", sans-serif; font-size: 10.5px; color: #111827; line-height: 1.45; }
    h1 { font-size: 19px; margin: 0 0 2px 0; }
    h2 { font-size: 11px; margin: 13px 0 4px 0; text-transform: uppercase; letter-spacing: .6px; color: #475467; }
    .muted { color: #667085; }
    .head { width: 100%; border-bottom: 2px solid #111827; padding-bottom: 10px; margin-bottom: 4px; }
    .head td { vertical-align: top; }
    .right { text-align: right; }
    table.grid { width: 100%; border-collapse: collapse; }
    table.grid th { text-align: left; font-size: 9px; text-transform: uppercase; letter-spacing: .5px; color: #667085; border-bottom: 1px solid #cbd0d8; padding: 5px 6px; }
    table.grid td { padding: 4px 6px; border-bottom: 1px solid #e4e7ec; }
    table.grid .num { text-align: right; white-space: nowrap; }
    table.grid tr.total td { font-weight: bold; border-top: 1.5px solid #111827; border-bottom: 0; background: #f2f4f7; }
    table.grid tr.key td { font-weight: bold; background: #fff4e0; border-top: 1.5px solid #b45309; border-bottom: 1.5px solid #b45309; }
    .ref { width: 28px; color: #667085; }
    .draft { border: 1.5px solid #b42318; color: #b42318; padding: 8px 10px; margin: 10px 0; font-weight: bold; }
    .box { border: 1px solid #cbd0d8; padding: 8px 10px; margin-top: 6px; }
    .pill { font-weight: bold; border: 1px solid #111827; padding: 1px 8px; }
    .sign { width: 100%; page-break-inside: avoid; }
    .sign td { padding-top: 22px; width: 50%; }
    .line { border-top: 1px solid #111827; padding-top: 3px; margin-right: 24px; font-size: 9px; color: #667085; }
    .foot { position: fixed; bottom: -36px; left: 0; right: 0; font-size: 8.5px; color: #667085; border-top: 1px solid #e4e7ec; padding-top: 5px; }
</style>
</head>
<body>
    <div class="foot">
        {{ $businessName }} · Gaming tax return for {{ $month->format('F Y') }} · Generated {{ now($tz)->format('j M Y, g:ia') }} ({{ $tz }})
        @if ($fingerprint) · Reference {{ $fingerprint }} @endif
    </div>

    <table class="head"><tr>
        <td>
            <h1>Gaming tax return</h1>
            <div>{{ $businessName }}</div>
            @if ($taxId) <div class="muted">Tax ID: {{ $taxId }}</div> @endif
        </td>
        <td class="right">
            <div style="font-size:15px;font-weight:bold">{{ $month->format('F Y') }}</div>
            <div class="muted">{{ $month->copy()->startOfMonth()->format('j M Y') }} to {{ $month->copy()->endOfMonth()->format('j M Y') }}</div>
            <div style="margin-top:4px"><span class="pill">{{ strtoupper($statusText) }}</span></div>
        </td>
    </tr></table>

    @if ($s['status'] === 'open')
        <div class="draft">DRAFT. This month is not locked, so these figures can still change. Lock the month before you file it.</div>
    @endif

    <h2>Calculation</h2>
    <table class="grid">
        <thead><tr><th class="ref"></th><th>Line</th><th class="num">Amount</th></tr></thead>
        <tbody>
            <tr><td class="ref">A</td><td>Ticket sales in the month (purchases that went through)</td><td class="num">{{ $naira($s['sales']) }}</td></tr>
            <tr><td class="ref">B</td><td>Less: ticket money refunded (cancelled raffles and refunds)</td><td class="num">{{ $naira(-$s['refunds']) }}</td></tr>
            <tr><td class="ref">C</td><td>Sales after refunds (A minus B)</td><td class="num">{{ $naira($s['net_sales']) }}</td></tr>
            <tr><td class="ref">D</td><td>Less: prizes won in the month (cash value, counted from the day awarded)</td><td class="num">{{ $naira(-$s['prizes']) }}</td></tr>
            <tr><td class="ref">E</td><td>Sales minus prizes (C minus D)</td><td class="num">{{ $naira($s['margin']) }}</td></tr>
            @if ($s['carried_in'] > 0)
                <tr><td class="ref">F</td><td>Less: shortfall brought in from earlier months</td><td class="num">{{ $naira(-$s['carried_in']) }}</td></tr>
            @endif
            <tr class="total"><td class="ref">G</td><td>Taxable amount</td><td class="num">{{ $naira($s['taxable']) }}</td></tr>
            <tr><td class="ref">H</td><td>Tax rate</td><td class="num">{{ $rate }}%</td></tr>
            <tr class="key"><td class="ref">I</td><td>TAX DUE (G x H)</td><td class="num">{{ $naira($s['tax_due']) }}</td></tr>
            @if ($s['carried_out'] > 0)
                <tr><td class="ref">J</td><td>Shortfall carried into the next month</td><td class="num">{{ $naira($s['carried_out']) }}</td></tr>
            @endif
        </tbody>
    </table>
    <p class="muted" style="margin-top:6px">{{ $ruleText }} Amounts in Naira (₦).</p>

    <h2>Filing and payment</h2>
    <table class="grid">
        <tbody>
            <tr><td style="width:32%">Due date</td><td>{{ \Illuminate\Support\Carbon::parse($s['due_on'])->format('j F Y') }}</td></tr>
            <tr><td>Locked</td><td>{{ $rec?->locked_at ? $rec->locked_at->setTimezone($tz)->format('j M Y, g:ia') : 'Not locked yet' }}</td></tr>
            <tr><td>Filed</td><td>@if ($rec?->filed_at) {{ $rec->filed_at->setTimezone($tz)->format('j M Y') }}@if ($rec->filing_reference) · Reference {{ $rec->filing_reference }}@endif @else Not filed yet @endif</td></tr>
            <tr><td>Paid</td><td>@if ($rec?->paid_at) {{ $naira($rec->paid_amount) }} on {{ $rec->paid_at->setTimezone($tz)->format('j M Y') }}@if ($rec->payment_reference) · Reference {{ $rec->payment_reference }}@endif @else Not paid yet @endif</td></tr>
        </tbody>
    </table>

    <h2>By raffle</h2>
    <table class="grid">
        <thead><tr><th>Raffle</th><th class="num">Ticket sales</th><th class="num">Prizes won</th><th class="num">Sales minus prizes</th></tr></thead>
        <tbody>
            @forelse ($s['by_raffle'] as $r)
                <tr><td>{{ $r['raffle'] }}</td><td class="num">{{ $naira($r['sales']) }}</td><td class="num">{{ $naira($r['prizes']) }}</td><td class="num">{{ $naira($r['sales'] - $r['prizes']) }}</td></tr>
            @empty
                <tr><td colspan="4" class="muted">No ticket sales or prizes in this month.</td></tr>
            @endforelse
        </tbody>
    </table>
    <p class="muted" style="margin-top:4px">The tax is worked out on the whole month (lines A to I), so a raffle that paid out more than it sold is set against the others. Refunds are not split by raffle in this table.</p>

    <h2>How these figures were worked out</h2>
    <div class="box">
        Ticket sales are ticket purchases that went through in the month, paid from the wallet, from winnings or by bank transfer. Refunds are ticket money returned to customers.
        Prizes are the cash value set on each prize level, counted in the month the prize was awarded, whether or not it has been paid out yet.
        Months follow {{ $tz }} time. Once a month is locked its figures, the rate and the shortfall rule cannot change.
    </div>

    <table class="sign"><tr>
        <td><div class="line">Prepared by</div></td>
        <td><div class="line">Reviewed by / date</div></td>
    </tr></table>
</body>
</html>
