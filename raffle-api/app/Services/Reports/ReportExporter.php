<?php

namespace App\Services\Reports;

use App\Filament\Support\LedgerReasons;
use App\Models\Legacy\RaffleTransaction;
use App\Models\Legacy\RaffleWinner;
use App\Models\Legacy\WpUser;
use App\Models\Raffle;
use App\Models\ReferralCommission;
use App\Models\WalletLedgerEntry;
use App\Models\WithdrawalRequest;
use Illuminate\Support\Carbon;
use InvalidArgumentException;

/**
 * Spreadsheet (CSV) downloads for accounting — Finance → Downloads.
 * Each report covers whole days in the business time zone, streams rows
 * in chunks (fine for years of data) and uses plain column names.
 */
final class ReportExporter
{
    public const REPORTS = [
        'sales' => 'Ticket sales',
        'topups' => 'Top-ups (money in)',
        'withdrawals' => 'Withdrawals paid (money out)',
        'winners' => 'Winners & prizes',
        'ledger' => 'Every money movement',
        'referrals' => 'Referral commission paid',
        'signups' => 'New customers',
    ];

    public const SALE_TYPES = ['ticket_purchase_wallet', 'ticket_purchase_earnings', 'ticket_purchase'];

    public const DONE = ['verified_final', 'completed'];

    /** @return array{0: Carbon, 1: Carbon} UTC range covering the given business days */
    public static function range(string $from, string $to): array
    {
        $tz = config('raffles.timezone', 'Africa/Lagos');

        return [
            Carbon::parse($from, $tz)->startOfDay()->utc(),
            Carbon::parse($to, $tz)->endOfDay()->utc(),
        ];
    }

    /** A spreadsheet cell that can't run as a formula: cells starting with = + - @ are formulas in Excel, so they get quoted. */
    public static function safeCell(mixed $value): mixed
    {
        return is_string($value) && preg_match('/^[=+\-@]/', $value) ? "'".$value : $value;
    }

    /** @return list<string> */
    public function headings(string $report): array
    {
        return match ($report) {
            'sales' => ['Date', 'Customer', 'Email', 'Raffle', 'Tickets', 'Ticket numbers', 'Amount (NGN)', 'Paid with', 'Reference'],
            'topups' => ['Date', 'Customer', 'Email', 'Amount (NGN)', 'Details'],
            'withdrawals' => ['Paid (last change)', 'Customer', 'Email', 'Bank', 'Account number', 'Account name', 'Sent (NGN)', 'Fee (NGN)', 'Requested (NGN)'],
            'winners' => ['Won', 'Raffle', 'Ticket', 'Prize', 'Cash value (NGN)', 'Winner', 'Email', 'Paid to winner'],
            'ledger' => ['Date', 'Customer', 'Email', 'Balance', 'In/Out', 'Amount (NGN)', 'What', 'Details'],
            'referrals' => ['Date', 'Referrer', 'Referrer email', 'Friend', 'Friend\'s first top-up (NGN)', 'Commission (NGN)', 'Rate %'],
            'signups' => ['Joined', 'Username', 'Name', 'Email', 'Wallet (NGN)', 'Winnings (NGN)'],
            default => throw new InvalidArgumentException("Unknown report {$report}"),
        };
    }

    /**
     * Rows for the report, lazily.
     *
     * @return iterable<list<string|int|float|null>>
     */
    public function rows(string $report, Carbon $from, Carbon $to): iterable
    {
        $local = fn ($date) => $date ? Carbon::parse($date)->tz(config('raffles.timezone'))->format('Y-m-d H:i') : '';
        $name = fn (?WpUser $u) => $u?->display_name ?: $u?->user_login;
        $raffles = Raffle::query()->pluck('title', 'public_id');

        switch ($report) {
            case 'sales':
                $query = RaffleTransaction::query()->with(['user', 'entries'])
                    ->whereIn('type', self::SALE_TYPES)->whereIn('status', self::DONE)
                    ->whereBetween('created_at', [$from, $to])->orderBy('id');
                foreach ($query->lazyById(500) as $t) {
                    $raffleId = $t->entries->first()?->raffle_id ?? $t->pending_raffle_id;
                    yield [$local($t->created_at), $name($t->user), $t->user?->user_email, $raffleId ? ($raffles[$raffleId] ?? "Raffle #{$raffleId}") : '',
                        $t->entries->count(), $t->entries->pluck('ticket_number')->sort()->implode(' '), (float) $t->claimed_amount,
                        ['ticket_purchase_wallet' => 'Wallet', 'ticket_purchase_earnings' => 'Winnings', 'ticket_purchase' => 'Bank transfer'][$t->type] ?? $t->type, $t->order_id];
                }
                break;

            case 'topups':
            case 'ledger':
                $query = WalletLedgerEntry::query()->with('user')->whereBetween('created_at', [$from, $to])
                    ->when($report === 'topups', fn ($q) => $q->where('reason', 'deposit')->where('direction', 'credit'))
                    ->orderBy('id');
                foreach ($query->lazyById(1000) as $e) {
                    yield $report === 'topups'
                        ? [$local($e->created_at), $name($e->user), $e->user?->user_email, (float) $e->amount, $e->description]
                        : [$local($e->created_at), $name($e->user), $e->user?->user_email, $e->balance_type === 'earnings' ? 'Winnings' : 'Wallet',
                            $e->direction === 'credit' ? 'In' : 'Out', (float) $e->amount, LedgerReasons::label($e->reason), $e->description];
                }
                break;

            case 'withdrawals':
                $query = WithdrawalRequest::query()->with(['user', 'bankAccount'])->where('status', 'paid')
                    ->whereBetween('updated_at', [$from, $to])->orderBy('id');
                foreach ($query->lazyById(500) as $w) {
                    yield [$local($w->updated_at), $name($w->user), $w->user?->user_email, $w->bankAccount?->bank_name, $w->bankAccount?->account_number,
                        $w->bankAccount?->account_name, (float) $w->amount_to_send, (float) $w->fee_amount, (float) $w->requested_amount];
                }
                break;

            case 'winners':
                $query = RaffleWinner::query()->with('user')->whereBetween('won_at', [$from, $to])->orderBy('id');
                foreach ($query->lazyById(500) as $w) {
                    yield [$local($w->won_at), $raffles[$w->raffle_id] ?? "Raffle #{$w->raffle_id}", $w->ticket_number, $w->prize_name,
                        (float) $w->prize_cash_value, $name($w->user), $w->user?->user_email, $w->is_credited ? 'Yes' : 'No'];
                }
                break;

            case 'referrals':
                $query = ReferralCommission::query()->with(['referrer', 'referee'])->whereBetween('created_at', [$from, $to])->orderBy('id');
                foreach ($query->lazyById(500) as $r) {
                    yield [$local($r->created_at), $name($r->referrer), $r->referrer?->user_email, $name($r->referee),
                        (float) $r->deposit_amount, (float) $r->commission_amount, round((float) $r->commission_rate * 100, 2)];
                }
                break;

            case 'signups':
                $query = WpUser::query()->with('wallet')->whereBetween('user_registered', [$from, $to])->orderBy('ID');
                foreach ($query->lazyById(500, 'ID') as $u) {
                    yield [$local($u->user_registered), $u->user_login, $u->display_name, $u->user_email,
                        (float) ($u->wallet->wallet_balance ?? 0), (float) ($u->wallet->earnings_balance ?? 0)];
                }
                break;

            default:
                throw new InvalidArgumentException("Unknown report {$report}");
        }
    }

    /** Totals shown on the Downloads page for the chosen days. @return array<string, float|int> */
    public function totals(Carbon $from, Carbon $to): array
    {
        return [
            'sales' => (float) RaffleTransaction::query()->whereIn('type', self::SALE_TYPES)->whereIn('status', self::DONE)->whereBetween('created_at', [$from, $to])->sum('claimed_amount'),
            'topups' => (float) WalletLedgerEntry::query()->where('reason', 'deposit')->where('direction', 'credit')->whereBetween('created_at', [$from, $to])->sum('amount'),
            'withdrawals' => (float) WithdrawalRequest::query()->where('status', 'paid')->whereBetween('updated_at', [$from, $to])->sum('amount_to_send'),
            'prizes' => (float) WalletLedgerEntry::query()->where('reason', 'prize_payout')->where('direction', 'credit')->whereBetween('created_at', [$from, $to])->sum('amount'),
        ];
    }
}
