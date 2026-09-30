<?php

namespace App\Services\Reports;

use App\Models\Deposit;
use App\Models\Legacy\RaffleEntry;
use App\Models\Legacy\RaffleTransaction;
use App\Models\Legacy\RaffleWinner;
use App\Models\Legacy\WpUser;
use App\Models\Raffle;
use App\Models\Wallet;
use App\Models\WalletLedgerEntry;
use App\Models\WithdrawalRequest;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Finance → Money reports: where the money came from and where it went,
 * for any range of business days. Each report is a plain table (headings
 * and rows) so the screen and the spreadsheet download show the very same
 * numbers.
 *
 * "What the site kept" is a simple guide, not an accounting statement:
 * ticket sales, minus prizes credited, refunds, bonuses and commissions
 * given away. Top-ups are not income (they become customers' balances) and
 * withdrawals are not a cost (they're customers taking their money back),
 * so neither counts towards it.
 */
final class MoneyReports
{
    public const REPORTS = [
        'overview' => 'Money in and out',
        'raffles' => 'Each raffle',
        'topups' => 'Top-ups by payment method',
        'withdrawals' => 'Withdrawals',
        'holding' => 'What customers hold now',
    ];

    public const GROUPS = ['day' => 'By day', 'week' => 'By week', 'month' => 'By month'];

    /** The longest stretch the day/week/month table covers. */
    public const MAX_DAYS = 400;

    private const BONUS_REASONS = ['signup_bonus', 'deposit_bonus', 'promo_bonus'];

    private const COMMISSION_REASONS = ['referral_commission', 'affiliate_commission'];

    /** Every ledger reason the overview cares about. */
    private const LEDGER_REASONS = ['deposit', 'prize_payout', 'ticket_purchase_refunded', ...self::BONUS_REASONS, ...self::COMMISSION_REASONS];

    /**
     * The headline numbers for the days.
     *
     * @return array<string, float>
     */
    public function summary(Carbon $from, Carbon $to): array
    {
        $ledger = WalletLedgerEntry::query()->whereBetween('created_at', [$from, $to])->whereIn('reason', self::LEDGER_REASONS)
            ->where('direction', 'credit')->selectRaw('reason, SUM(amount) as total')->groupBy('reason')->pluck('total', 'reason');
        $sum = fn (array $reasons) => round((float) collect($reasons)->sum(fn ($r) => (float) ($ledger[$r] ?? 0)), 2);

        $paid = WithdrawalRequest::query()->where('status', 'paid')->whereBetween('updated_at', [$from, $to]);

        $out = [
            'sales' => round((float) $this->sales()->whereBetween('created_at', [$from, $to])->sum('claimed_amount'), 2),
            'topups' => $sum(['deposit']),
            'withdrawals' => round((float) (clone $paid)->sum('amount_to_send'), 2),
            'withdrawal_fees' => round((float) (clone $paid)->sum('fee_amount'), 2),
            'prizes' => $sum(['prize_payout']),
            'refunds' => $sum(['ticket_purchase_refunded']),
            'bonuses' => $sum(self::BONUS_REASONS),
            'commissions' => $sum(self::COMMISSION_REASONS),
        ];

        $out['given'] = round($out['bonuses'] + $out['commissions'], 2);
        $out['kept'] = round($out['sales'] - $out['prizes'] - $out['refunds'] - $out['bonuses'] - $out['commissions'], 2);

        return $out;
    }

    /** The days just before the chosen ones, same length, for "compared with before". @return array{0: Carbon, 1: Carbon} */
    public static function previous(Carbon $from, Carbon $to): array
    {
        $seconds = (int) round($from->diffInSeconds($to)) + 1;

        return [$from->copy()->subSeconds($seconds), $from->copy()->subSecond()];
    }

    /**
     * Money in and out split by day, week or month (business days).
     *
     * @return array{headings: list<string>, rows: list<list<string|float>>, totals: list<string|float>}
     */
    public function overview(Carbon $from, Carbon $to, string $group = 'day'): array
    {
        if (! array_key_exists($group, self::GROUPS)) {
            throw new InvalidArgumentException("Unknown grouping {$group}");
        }

        if ($from->diffInDays($to) > self::MAX_DAYS) {
            throw new InvalidArgumentException('Pick a range of '.self::MAX_DAYS.' days or fewer.');
        }

        $tz = config('raffles.timezone', 'Africa/Lagos');
        $periods = [];
        $add = function (string $bucket, string $column, float $amount) use (&$periods, $group, $tz) {
            $local = Carbon::parse($bucket, 'UTC')->tz($tz);
            [$key, $label] = match ($group) {
                'week' => [$local->copy()->startOfWeek()->toDateString(), 'Week of '.$local->copy()->startOfWeek()->format('j M Y')],
                'month' => [$local->format('Y-m'), $local->format('F Y')],
                default => [$local->toDateString(), $local->format('D j M Y')],
            };
            $periods[$key] ??= ['label' => $label, 'sales' => 0.0, 'topups' => 0.0, 'withdrawals' => 0.0, 'prizes' => 0.0, 'refunds' => 0.0, 'given' => 0.0];
            $periods[$key][$column] += $amount;
        };

        $hour = fn (string $column) => $this->hourBucket($column);

        $this->sales()->whereBetween('created_at', [$from, $to])
            ->selectRaw($hour('created_at').' as bucket, SUM(claimed_amount) as total')->groupBy('bucket')->get()
            ->each(fn ($r) => $add($r->bucket, 'sales', (float) $r->total));

        WalletLedgerEntry::query()->whereBetween('created_at', [$from, $to])->whereIn('reason', self::LEDGER_REASONS)->where('direction', 'credit')
            ->selectRaw($hour('created_at').' as bucket, reason, SUM(amount) as total')->groupBy('bucket', 'reason')->get()
            ->each(fn ($r) => $add($r->bucket, match (true) {
                $r->reason === 'deposit' => 'topups',
                $r->reason === 'prize_payout' => 'prizes',
                $r->reason === 'ticket_purchase_refunded' => 'refunds',
                default => 'given',
            }, (float) $r->total));

        WithdrawalRequest::query()->where('status', 'paid')->whereBetween('updated_at', [$from, $to])
            ->selectRaw($hour('updated_at').' as bucket, SUM(amount_to_send) as total')->groupBy('bucket')->get()
            ->each(fn ($r) => $add($r->bucket, 'withdrawals', (float) $r->total));

        ksort($periods);

        $rows = [];
        $totals = array_fill_keys(['sales', 'topups', 'withdrawals', 'prizes', 'refunds', 'given', 'kept'], 0.0);

        foreach ($periods as $p) {
            $p['kept'] = $p['sales'] - $p['prizes'] - $p['refunds'] - $p['given'];
            foreach ($totals as $k => $_) {
                $totals[$k] += $p[$k];
            }
            $rows[] = [$p['label'], ...array_map(fn ($k) => round($p[$k], 2), array_keys($totals))];
        }

        return [
            'headings' => ['Period', 'Ticket sales (₦)', 'Top-ups in (₦)', 'Withdrawals paid (₦)', 'Prizes credited (₦)', 'Refunds (₦)', 'Bonuses and commissions (₦)', 'What the site kept (₦)'],
            'rows' => $rows,
            'totals' => ['Total', ...array_map(fn ($v) => round($v, 2), array_values($totals))],
        ];
    }

    /**
     * How each raffle did. Sales are for the chosen days; prizes and
     * refunds cover the whole raffle.
     *
     * @return array{headings: list<string>, rows: list<list<string|float|int>>, totals: list<string|float|int>}
     */
    public function raffles(Carbon $from, Carbon $to): array
    {
        $transactions = (new RaffleTransaction)->getTable();

        $sold = RaffleTransaction::query()
            ->joinSub(RaffleEntry::query()->selectRaw('txn_id, MIN(raffle_id) as raffle_id, COUNT(*) as tickets')->where('txn_id', '>', 0)->groupBy('txn_id'), 'e', 'e.txn_id', '=', "{$transactions}.id")
            ->whereIn("{$transactions}.type", ReportExporter::SALE_TYPES)->whereIn("{$transactions}.status", ReportExporter::DONE)
            ->whereBetween("{$transactions}.created_at", [$from, $to])
            ->selectRaw("e.raffle_id as raffle_id, SUM(e.tickets) as tickets, SUM({$transactions}.claimed_amount) as sales")
            ->groupBy('e.raffle_id')->get()->keyBy('raffle_id');

        $prizes = RaffleWinner::query()->whereIn('raffle_id', $sold->keys())->selectRaw('raffle_id, SUM(prize_cash_value) as total')->groupBy('raffle_id')->pluck('total', 'raffle_id');
        $raffles = Raffle::query()->whereIn('public_id', $sold->keys())->get()->keyBy('public_id');

        $rows = [];
        $totals = ['tickets' => 0, 'sales' => 0.0, 'prizes' => 0.0, 'refunds' => 0.0, 'result' => 0.0];

        foreach ($sold->sortByDesc(fn ($r) => (float) $r->sales) as $raffleId => $row) {
            $raffle = $raffles[$raffleId] ?? null;
            $sales = round((float) $row->sales, 2);
            $prize = round((float) ($prizes[$raffleId] ?? 0), 2);
            $refund = round((float) ($raffle?->refunded_total ?? 0), 2);
            $result = round($sales - $prize - $refund, 2);

            $rows[] = [$raffle?->title ?? "Raffle #{$raffleId}", $raffle?->cancelled_at ? 'Cancelled' : ucfirst((string) ($raffle?->status ?? 'unknown')), (int) $row->tickets, $sales, $prize, $refund, $result];
            $totals['tickets'] += (int) $row->tickets;
            $totals['sales'] += $sales;
            $totals['prizes'] += $prize;
            $totals['refunds'] += $refund;
            $totals['result'] += $result;
        }

        return [
            'headings' => ['Raffle', 'State', 'Tickets sold', 'Ticket sales (₦)', 'Prizes worth (₦)', 'Refunded (₦)', 'What it earned (₦)'],
            'rows' => $rows,
            'totals' => ['Total', '', $totals['tickets'], ...array_map(fn ($v) => round($v, 2), [$totals['sales'], $totals['prizes'], $totals['refunds'], $totals['result']])],
        ];
    }

    /**
     * Top-ups through each payment provider, and how many worked.
     *
     * @return array{headings: list<string>, rows: list<list<string|float|int>>, totals: list<string|float|int>}
     */
    public function topups(Carbon $from, Carbon $to): array
    {
        $byGateway = Deposit::query()->whereBetween('created_at', [$from, $to])
            ->selectRaw("gateway, COUNT(*) as attempts, SUM(CASE WHEN status = 'successful' THEN 1 ELSE 0 END) as worked, SUM(CASE WHEN status = 'amount_mismatch' THEN 1 ELSE 0 END) as mismatched, SUM(CASE WHEN status = 'successful' THEN amount ELSE 0 END) as total")
            ->groupBy('gateway')->orderByDesc('total')->get();

        $rows = [];
        $totals = ['attempts' => 0, 'worked' => 0, 'mismatched' => 0, 'total' => 0.0];

        foreach ($byGateway as $g) {
            $rows[] = [ucfirst((string) $g->gateway), (int) $g->attempts, (int) $g->worked, (int) $g->mismatched, $g->attempts > 0 ? round($g->worked / $g->attempts * 100, 1) : 0.0, round((float) $g->total, 2)];
            $totals['attempts'] += (int) $g->attempts;
            $totals['worked'] += (int) $g->worked;
            $totals['mismatched'] += (int) $g->mismatched;
            $totals['total'] += (float) $g->total;
        }

        // Money that reached wallets some other way (approved bank transfers, manual credits).
        $credited = (float) WalletLedgerEntry::query()->where('reason', 'deposit')->where('direction', 'credit')->whereBetween('created_at', [$from, $to])->sum('amount');
        if ($credited - $totals['total'] > 0.005) {
            $other = round($credited - $totals['total'], 2);
            $rows[] = ['Other (bank transfers, manual)', '', '', '', '', $other];
            $totals['total'] += $other;
        }

        return [
            'headings' => ['Payment method', 'Tries', 'Worked', 'Wrong amount', 'Worked (%)', 'Money in (₦)'],
            'rows' => $rows,
            'totals' => ['Total', $totals['attempts'], $totals['worked'], $totals['mismatched'], $totals['attempts'] > 0 ? round($totals['worked'] / $totals['attempts'] * 100, 1) : 0.0, round($totals['total'], 2)],
        ];
    }

    /**
     * Withdrawal requests made in the days, by where they stand.
     *
     * @return array{headings: list<string>, rows: list<list<string|float|int>>, totals: list<string|float|int>}
     */
    public function withdrawals(Carbon $from, Carbon $to): array
    {
        $rows = [];
        $totals = ['count' => 0, 'requested' => 0.0, 'fee' => 0.0, 'sent' => 0.0];
        $labels = ['pending' => 'Waiting to be paid', 'paid' => 'Paid', 'rejected' => 'Refused (money returned)'];

        foreach ($labels as $status => $label) {
            $q = WithdrawalRequest::query()->where('status', $status)->whereBetween('created_at', [$from, $to]);
            $count = (int) (clone $q)->count();
            $requested = round((float) (clone $q)->sum('requested_amount'), 2);
            $fee = round((float) (clone $q)->sum('fee_amount'), 2);
            $sent = round((float) (clone $q)->sum('amount_to_send'), 2);

            $hours = '';
            if ($status === 'paid' && $count > 0) {
                $avg = (clone $q)->get(['created_at', 'updated_at'])->avg(fn ($w) => $w->created_at->diffInMinutes($w->updated_at) / 60);
                $hours = round((float) $avg, 1);
            }

            $rows[] = [$label, $count, $requested, $fee, $sent, $hours];
            $totals['count'] += $count;
            $totals['requested'] += $requested;
            $totals['fee'] += $fee;
            $totals['sent'] += $sent;
        }

        return [
            'headings' => ['Where it stands', 'Requests', 'Asked for (₦)', 'Fees (₦)', 'To send (₦)', 'Average hours to pay'],
            'rows' => $rows,
            'totals' => ['Total', $totals['count'], round($totals['requested'], 2), round($totals['fee'], 2), round($totals['sent'], 2), ''],
        ];
    }

    /**
     * What customers hold right now (the money the site owes them if they
     * all withdrew), and who holds the most. Not tied to the chosen days.
     *
     * @return array{headings: list<string>, rows: list<list<string|float|int>>, totals: list<string|float|int>}
     */
    public function holding(): array
    {
        $wallet = (float) Wallet::query()->sum('wallet_balance');
        $winnings = (float) Wallet::query()->sum('earnings_balance');
        $waiting = (float) WithdrawalRequest::query()->where('status', 'pending')->sum('amount_to_send');

        $rows = [
            ['Spending wallets', Wallet::query()->where('wallet_balance', '>', 0)->count(), round($wallet, 2)],
            ['Winnings (can be withdrawn)', Wallet::query()->where('earnings_balance', '>', 0)->count(), round($winnings, 2)],
            ['Withdrawals asked for, not paid yet', WithdrawalRequest::query()->where('status', 'pending')->count(), round($waiting, 2)],
        ];

        $top = Wallet::query()->orderByRaw('(wallet_balance + earnings_balance) DESC')->limit(10)->get();
        $names = WpUser::query()->whereIn('ID', $top->pluck('user_id'))->get()->keyBy('ID');
        foreach ($top as $w) {
            $u = $names[$w->user_id] ?? null;
            $rows[] = ['Top holder: '.($u?->display_name ?: $u?->user_login ?: "#{$w->user_id}").($u ? ' ('.$u->user_email.')' : ''), '', round((float) $w->wallet_balance + (float) $w->earnings_balance, 2)];
        }

        return [
            'headings' => ['What', 'Customers', 'Amount (₦)'],
            'rows' => $rows,
            'totals' => ['Wallets and winnings together', '', round($wallet + $winnings, 2)],
        ];
    }

    /** Any report by name, for the screen and the download. */
    public function report(string $name, Carbon $from, Carbon $to, string $group = 'day'): array
    {
        return match ($name) {
            'overview' => $this->overview($from, $to, $group),
            'raffles' => $this->raffles($from, $to),
            'topups' => $this->topups($from, $to),
            'withdrawals' => $this->withdrawals($from, $to),
            'holding' => $this->holding(),
            default => throw new InvalidArgumentException("Unknown report {$name}"),
        };
    }

    /** Ticket purchases that went through, any way of paying. */
    private function sales(): Builder
    {
        return RaffleTransaction::query()->whereIn('type', ReportExporter::SALE_TYPES)->whereIn('status', ReportExporter::DONE);
    }

    /** The hour (in UTC) a row belongs to, so days can be worked out in business time without a database-specific time-zone function. */
    private function hourBucket(string $column): string
    {
        return DB::connection()->getDriverName() === 'sqlite'
            ? "strftime('%Y-%m-%d %H:00:00', {$column})"
            : "DATE_FORMAT({$column}, '%Y-%m-%d %H:00:00')";
    }
}
