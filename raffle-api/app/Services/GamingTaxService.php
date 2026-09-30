<?php

namespace App\Services;

use App\Models\GamingTaxPeriod;
use App\Models\Legacy\RaffleTransaction;
use App\Models\Legacy\RaffleWinner;
use App\Models\Legacy\WpUser;
use App\Models\Raffle;
use App\Models\WalletLedgerEntry;
use App\Services\Reports\ReportExporter;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * The monthly gaming tax: a percentage (2.5% to start) of what a month's
 * ticket sales are left with after the prizes won that month.
 *
 *   sales  = ticket purchases that went through in the month
 *          − ticket money given back (cancelled raffles, refunds)
 *   prizes = the cash value of prizes AWARDED in the month (an unpaid prize
 *            still counts, from the day it is awarded)
 *   margin = sales − prizes
 *
 * When prizes were worth more than sales, staff choose (Settings → Payments →
 * Gaming tax) what happens: that month owes nothing and the shortfall is
 * forgotten, or the shortfall is carried into the next month.
 *
 * Months are worked out live until staff LOCK them. Locking freezes the
 * figures, the rate and the shortfall rule in gaming_tax_periods, so a locked
 * month can never change by accident, however the settings or the data move
 * afterwards. A locked month then moves to filed and paid. Months are locked
 * in order, and only once they have ended, so a carried shortfall is never
 * worked out from a month that could still change.
 *
 * Month boundaries follow the business time zone (raffles.timezone).
 */
class GamingTaxService
{
    public const RULES = ['zero', 'carry_forward'];

    public function __construct(private readonly AdminAuditLogService $audit) {}

    // ---- Settings ---------------------------------------------------------

    public function rate(): float
    {
        return max(0.0, min(100.0, (float) config('gaming_tax.rate', 2.5)));
    }

    public function shortfallRule(): string
    {
        $rule = (string) config('gaming_tax.shortfall', 'zero');

        return in_array($rule, self::RULES, true) ? $rule : 'zero';
    }

    public function dueDay(): int
    {
        return max(1, min(28, (int) config('gaming_tax.due_day', 21)));
    }

    // ---- Months -----------------------------------------------------------

    public function timezone(): string
    {
        return (string) config('raffles.timezone', 'Africa/Lagos');
    }

    /** "2026-09" for a moment in time, in business time. */
    public function periodOf(Carbon $moment): string
    {
        return $moment->copy()->setTimezone($this->timezone())->format('Y-m');
    }

    public function isValidPeriod(string $period): bool
    {
        return preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $period) === 1;
    }

    /** The first and last moment of a month, as UTC times for the database. @return array{0: Carbon, 1: Carbon} */
    public function bounds(string $period): array
    {
        $this->assertPeriod($period);
        $start = Carbon::createFromFormat('!Y-m', $period, $this->timezone())->startOfMonth();

        return [$start->copy()->utc(), $start->copy()->endOfMonth()->utc()];
    }

    /** True once the month is over, in business time. */
    public function hasEnded(string $period): bool
    {
        return now()->gt($this->bounds($period)[1]);
    }

    public function previousPeriod(string $period): string
    {
        return Carbon::createFromFormat('!Y-m', $period, $this->timezone())->subMonthNoOverflow()->format('Y-m');
    }

    public function dueDate(string $period): Carbon
    {
        $next = Carbon::createFromFormat('!Y-m', $period, $this->timezone())->addMonthNoOverflow();

        return $next->setDay($this->dueDay())->startOfDay();
    }

    /**
     * The months worth listing, newest first: from the first month with any
     * ticket sale or locked record up to the current month.
     *
     * @return list<string>
     */
    public function months(int $limit = 24): array
    {
        $first = RaffleTransaction::query()->whereIn('type', ReportExporter::SALE_TYPES)->whereIn('status', ReportExporter::DONE)->min('created_at');
        $firstLocked = GamingTaxPeriod::query()->min('period');
        $current = $this->periodOf(now());

        $start = $first ? $this->periodOf(Carbon::parse($first, 'UTC')) : $current;

        if ($firstLocked && $firstLocked < $start) {
            $start = $firstLocked;
        }

        $months = [];
        $cursor = $current;

        while ($cursor >= $start && count($months) < $limit) {
            $months[] = $cursor;
            $cursor = $this->previousPeriod($cursor);
        }

        return $months;
    }

    // ---- The numbers ------------------------------------------------------

    /**
     * A month's statement. A locked month returns exactly what was locked;
     * any other month is worked out now with today's settings.
     *
     * @return array{
     *   period: string, status: string, sales: float, refunds: float, net_sales: float, prizes: float, margin: float,
     *   carried_in: float, taxable: float, carried_out: float, rate: float, shortfall_rule: string, tax_due: float,
     *   by_raffle: list<array{raffle: string, sales: float, prizes: float}>, due_on: string, ended: bool, record: ?GamingTaxPeriod
     * }
     */
    public function statement(string $period): array
    {
        $this->assertPeriod($period);
        $record = GamingTaxPeriod::query()->where('period', $period)->first();

        if ($record) {
            $net = round($record->sales - $record->refunds, 2);

            return [
                'period' => $period, 'status' => $record->status,
                'sales' => $record->sales, 'refunds' => $record->refunds, 'net_sales' => $net, 'prizes' => $record->prizes,
                'margin' => round($net - $record->prizes, 2),
                'carried_in' => $record->carried_in, 'taxable' => $record->taxable, 'carried_out' => $record->carried_out,
                'rate' => $record->rate, 'shortfall_rule' => $record->shortfall_rule, 'tax_due' => $record->tax_due,
                'by_raffle' => (array) $record->by_raffle,
                'due_on' => $this->dueDate($period)->format('Y-m-d'), 'ended' => $this->hasEnded($period), 'record' => $record,
            ];
        }

        return $this->live($period);
    }

    /** The maths, for a month that is not locked. */
    private function live(string $period): array
    {
        [$from, $to] = $this->bounds($period);

        $sales = round((float) $this->salesQuery()->whereBetween('created_at', [$from, $to])->sum('claimed_amount'), 2);
        $refunds = round((float) WalletLedgerEntry::query()->where('reason', 'ticket_purchase_refunded')->where('direction', 'credit')->whereBetween('created_at', [$from, $to])->sum('amount'), 2);
        $prizes = round((float) RaffleWinner::query()->whereBetween('won_at', [$from, $to])->sum('prize_cash_value'), 2);

        $rule = $this->shortfallRule();
        $carriedIn = $rule === 'carry_forward' ? $this->carriedInto($period) : 0.0;
        $math = self::work($sales, $refunds, $prizes, $carriedIn, $this->rate(), $rule);

        return [
            'period' => $period, 'status' => 'open',
            'sales' => $sales, 'refunds' => $refunds, 'net_sales' => $math['net_sales'], 'prizes' => $prizes, 'margin' => $math['margin'],
            'carried_in' => $carriedIn, 'taxable' => $math['taxable'], 'carried_out' => $math['carried_out'],
            'rate' => $this->rate(), 'shortfall_rule' => $rule, 'tax_due' => $math['tax_due'],
            'by_raffle' => $this->byRaffle($from, $to),
            'due_on' => $this->dueDate($period)->format('Y-m-d'), 'ended' => $this->hasEnded($period), 'record' => null,
        ];
    }

    /**
     * The tax maths on its own, so it is easy to check.
     *
     * @return array{net_sales: float, margin: float, taxable: float, carried_out: float, tax_due: float}
     */
    public static function work(float $sales, float $refunds, float $prizes, float $carriedIn, float $rate, string $rule): array
    {
        $net = round($sales - $refunds, 2);
        $margin = round($net - $prizes, 2);

        if ($rule === 'carry_forward') {
            $adjusted = round($margin - $carriedIn, 2);
            $taxable = max(0.0, $adjusted);
            $carriedOut = max(0.0, -$adjusted);
        } else {
            $taxable = max(0.0, $margin);
            $carriedOut = 0.0;
        }

        return [
            'net_sales' => $net,
            'margin' => $margin,
            'taxable' => round($taxable, 2),
            'carried_out' => round($carriedOut, 2),
            'tax_due' => round($taxable * $rate / 100, 2),
        ];
    }

    /** The shortfall a month inherits: what the month before it passed on (0 if that month isn't locked). */
    private function carriedInto(string $period): float
    {
        $previous = GamingTaxPeriod::query()->where('period', $this->previousPeriod($period))->first();

        return $previous ? (float) $previous->carried_out : 0.0;
    }

    /** @return list<array{raffle: string, sales: float, prizes: float}> */
    private function byRaffle(Carbon $from, Carbon $to): array
    {
        $transactions = (new RaffleTransaction)->getTable();

        $sold = RaffleTransaction::query()
            ->joinSub(\App\Models\Legacy\RaffleEntry::query()->selectRaw('txn_id, MIN(raffle_id) as raffle_id')->where('txn_id', '>', 0)->groupBy('txn_id'), 'e', 'e.txn_id', '=', "{$transactions}.id")
            ->whereIn("{$transactions}.type", ReportExporter::SALE_TYPES)->whereIn("{$transactions}.status", ReportExporter::DONE)
            ->whereBetween("{$transactions}.created_at", [$from, $to])
            ->selectRaw("e.raffle_id as raffle_id, SUM({$transactions}.claimed_amount) as sales")
            ->groupBy('e.raffle_id')->pluck('sales', 'raffle_id');

        $won = RaffleWinner::query()->whereBetween('won_at', [$from, $to])->selectRaw('raffle_id, SUM(prize_cash_value) as total')->groupBy('raffle_id')->pluck('total', 'raffle_id');

        $ids = $sold->keys()->merge($won->keys())->unique()->values();
        $titles = Raffle::query()->whereIn('public_id', $ids)->pluck('title', 'public_id');

        return $ids->map(fn ($id) => [
            'raffle' => (string) ($titles[$id] ?? "Raffle #{$id}"),
            'sales' => round((float) ($sold[$id] ?? 0), 2),
            'prizes' => round((float) ($won[$id] ?? 0), 2),
        ])->sortByDesc('sales')->values()->all();
    }

    /**
     * Things worth a look before locking a month.
     *
     * @return list<string>
     */
    public function attention(string $period): array
    {
        [$from, $to] = $this->bounds($period);
        $notes = [];

        $unpaid = RaffleWinner::query()->whereBetween('won_at', [$from, $to])->where('is_credited', false);
        if (($count = (clone $unpaid)->count()) > 0) {
            $notes[] = $count.' prize'.($count === 1 ? '' : 's').' awarded but not yet paid out (₦'.number_format((float) (clone $unpaid)->sum('prize_cash_value')).'). They count as prizes won from the day they are awarded.';
        }

        $noValue = RaffleWinner::query()->whereBetween('won_at', [$from, $to])->where('prize_cash_value', '<=', 0)->count();
        if ($noValue > 0) {
            $notes[] = $noValue.' prize'.($noValue === 1 ? ' has' : 's have').' no cash value, so '.($noValue === 1 ? 'it counts' : 'they count').' as ₦0. Set a cash value on the raffle\'s prize levels so the tax is not understated.';
        }

        $refunds = (float) WalletLedgerEntry::query()->where('reason', 'ticket_purchase_refunded')->where('direction', 'credit')->whereBetween('created_at', [$from, $to])->sum('amount');
        if ($refunds > 0) {
            $notes[] = '₦'.number_format($refunds).' of ticket money was refunded. It is already taken out of sales.';
        }

        // A shortfall can only be carried in from a month that is locked.
        if ($this->shortfallRule() === 'carry_forward'
            && GamingTaxPeriod::query()->where('period', '<', $period)->exists()
            && ! GamingTaxPeriod::query()->where('period', $this->previousPeriod($period))->exists()) {
            $notes[] = 'The month before is not locked yet, so a shortfall from it is not included here.';
        }

        return $notes;
    }

    // ---- Locking, filing, paying -------------------------------------------

    /** Why this month can't be locked right now, or null when it can. */
    public function whyCannotLock(string $period): ?string
    {
        $this->assertPeriod($period);

        if (GamingTaxPeriod::query()->where('period', $period)->exists()) {
            return 'This month is already locked.';
        }

        if (! $this->hasEnded($period)) {
            return 'You can lock a month once it has ended.';
        }

        if (GamingTaxPeriod::query()->where('period', '>', $period)->exists()) {
            return 'A later month is already locked, so this one can\'t be locked now.';
        }

        $previous = $this->previousPeriod($period);

        if (GamingTaxPeriod::query()->where('period', '<', $period)->exists() && ! GamingTaxPeriod::query()->where('period', $previous)->exists()) {
            return 'Lock the earlier months first. Months are locked in order.';
        }

        return null;
    }

    /** @throws RuntimeException */
    public function lock(WpUser $admin, string $period): GamingTaxPeriod
    {
        if ($why = $this->whyCannotLock($period)) {
            throw new RuntimeException($why);
        }

        return DB::transaction(function () use ($admin, $period) {
            $figures = $this->live($period);

            $record = GamingTaxPeriod::create([
                'period' => $period,
                'sales' => $figures['sales'], 'refunds' => $figures['refunds'], 'prizes' => $figures['prizes'],
                'carried_in' => $figures['carried_in'], 'taxable' => $figures['taxable'], 'carried_out' => $figures['carried_out'],
                'rate' => $figures['rate'], 'shortfall_rule' => $figures['shortfall_rule'], 'tax_due' => $figures['tax_due'],
                'by_raffle' => $figures['by_raffle'],
                'status' => 'locked', 'locked_at' => now(), 'locked_by' => $admin->ID,
            ]);

            GamingTaxReminders::forgetBadge();
            $this->audit->record($admin, 'gaming_tax.locked', GamingTaxPeriod::class, $record->id, [
                'period' => $period, 'sales' => $record->sales, 'refunds' => $record->refunds, 'prizes' => $record->prizes,
                'taxable' => $record->taxable, 'rate' => $record->rate, 'shortfall_rule' => $record->shortfall_rule, 'tax_due' => $record->tax_due,
            ]);

            return $record;
        });
    }

    /**
     * Reopens a locked month that has not been filed. Only the last locked
     * month can be reopened, so a month after it never rests on changed figures.
     *
     * @throws RuntimeException
     */
    public function reopen(WpUser $admin, string $period, string $reason): void
    {
        $reason = trim($reason);
        $record = GamingTaxPeriod::query()->where('period', $period)->first();

        if (! $record) {
            throw new RuntimeException('This month is not locked.');
        }

        if ($record->status !== 'locked') {
            throw new RuntimeException('A month that has been filed can\'t be reopened.');
        }

        if (GamingTaxPeriod::query()->where('period', '>', $period)->exists()) {
            throw new RuntimeException('A later month is already locked. Reopen that one first.');
        }

        if ($reason === '') {
            throw new RuntimeException('Say why you are reopening it.');
        }

        GamingTaxReminders::forgetBadge();
        $this->audit->record($admin, 'gaming_tax.reopened', GamingTaxPeriod::class, $record->id, ['period' => $period, 'reason' => $reason, 'tax_due_was' => $record->tax_due]);
        $record->delete();
    }

    /** @throws RuntimeException */
    public function markFiled(WpUser $admin, string $period, string $reference, ?Carbon $filedOn = null): GamingTaxPeriod
    {
        $record = GamingTaxPeriod::query()->where('period', $period)->first();

        if (! $record || $record->status !== 'locked') {
            throw new RuntimeException('Lock the month first. Only a locked month can be marked as filed.');
        }

        $record->update(['status' => 'filed', 'filed_at' => $filedOn ?? now(), 'filed_by' => $admin->ID, 'filing_reference' => trim($reference) ?: null]);
        GamingTaxReminders::forgetBadge();
        $this->audit->record($admin, 'gaming_tax.filed', GamingTaxPeriod::class, $record->id, ['period' => $period, 'reference' => $record->filing_reference]);

        return $record;
    }

    /** @throws RuntimeException */
    public function recordPayment(WpUser $admin, string $period, float $amount, string $reference, ?Carbon $paidOn = null): GamingTaxPeriod
    {
        $record = GamingTaxPeriod::query()->where('period', $period)->first();

        if (! $record || $record->status !== 'filed') {
            throw new RuntimeException('Mark the month as filed before recording the payment.');
        }

        if ($amount < 0) {
            throw new RuntimeException('The amount paid can\'t be negative.');
        }

        $record->update(['status' => 'paid', 'paid_at' => $paidOn ?? now(), 'paid_by' => $admin->ID, 'paid_amount' => round($amount, 2), 'payment_reference' => trim($reference) ?: null]);
        GamingTaxReminders::forgetBadge();
        $this->audit->record($admin, 'gaming_tax.paid', GamingTaxPeriod::class, $record->id, [
            'period' => $period, 'amount' => round($amount, 2), 'tax_due' => $record->tax_due, 'reference' => $record->payment_reference,
        ]);

        return $record;
    }

    // ---- Export -----------------------------------------------------------

    /**
     * Rows for the spreadsheet: one line per month.
     *
     * @return array{headings: list<string>, rows: list<list<string|float>>}
     */
    public function exportRows(string ...$periods): array
    {
        $rows = [];

        foreach ($periods as $period) {
            $s = $this->statement($period);
            $rows[] = [
                $period, ucfirst($s['status']), $s['sales'], $s['refunds'], $s['net_sales'], $s['prizes'], $s['margin'],
                $s['carried_in'], $s['taxable'], $s['carried_out'], $s['rate'], $s['tax_due'],
                $s['shortfall_rule'] === 'carry_forward' ? 'Carry forward' : 'Forgotten', $s['due_on'],
                $s['record']?->filed_at?->format('Y-m-d') ?? '', $s['record']?->filing_reference ?? '',
                $s['record']?->paid_at?->format('Y-m-d') ?? '', $s['record']?->paid_amount ?? '', $s['record']?->payment_reference ?? '',
            ];
        }

        return [
            'headings' => ['Month', 'Status', 'Ticket sales (₦)', 'Refunds (₦)', 'Sales after refunds (₦)', 'Prizes won (₦)', 'Sales minus prizes (₦)',
                'Shortfall brought in (₦)', 'Taxable amount (₦)', 'Shortfall carried on (₦)', 'Rate (%)', 'Tax due (₦)', 'If prizes beat sales', 'Due on',
                'Filed on', 'Filing reference', 'Paid on', 'Amount paid (₦)', 'Payment reference'],
            'rows' => $rows,
        ];
    }

    // ---- Internals --------------------------------------------------------

    private function salesQuery()
    {
        return RaffleTransaction::query()->whereIn('type', ReportExporter::SALE_TYPES)->whereIn('status', ReportExporter::DONE);
    }

    private function assertPeriod(string $period): void
    {
        if (! $this->isValidPeriod($period)) {
            throw new RuntimeException('That is not a month (use the form 2026-09).');
        }
    }
}
