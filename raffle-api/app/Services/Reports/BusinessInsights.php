<?php

namespace App\Services\Reports;

use App\Exceptions\AiUnavailableException;
use App\Models\Legacy\RaffleEntry;
use App\Models\Legacy\RaffleTransaction;
use App\Models\Legacy\WpUser;
use App\Models\Raffle;
use App\Models\SiteError;
use App\Models\SupportTicket;
use App\Models\Tutorial;
use App\Models\WalletLedgerEntry;
use App\Models\WithdrawalRequest;
use App\Services\Ai\GeminiClient;
use Illuminate\Support\Carbon;

/**
 * "Business insights" (OVERHAUL_CHECKLIST.md item 36): the replacement for
 * the old WordPress admin's "AI Insights (Brain)" page. The site works out
 * the numbers for a period and the one before it; Gemini only writes a
 * short plain-English summary of those numbers. Unlike the old page, no
 * customer names or emails are sent to the AI — only totals.
 */
class BusinessInsights
{
    public const PERIODS = ['day' => 'Today (so far)', 'week' => 'Last 7 days', 'month' => 'Last 30 days'];

    private const SALE_TYPES = ['ticket_purchase_wallet', 'ticket_purchase_earnings', 'ticket_purchase'];

    public function __construct(private readonly GeminiClient $gemini) {}

    /**
     * The period's figures next to the one before it.
     *
     * @return array{period: string, label: string, from: string, to: string, current: array<string, float|int>, previous: array<string, float|int>, top_raffles: list<array{title: string, tickets: int}>, waiting: array<string, float|int>}
     */
    public function figures(string $period): array
    {
        [$from, $to, $prevFrom, $prevTo] = $this->window($period);

        return [
            'period' => $period,
            'label' => self::PERIODS[$period],
            'from' => $from->toIso8601String(),
            'to' => $to->toIso8601String(),
            'current' => $this->totals($from, $to),
            'previous' => $this->totals($prevFrom, $prevTo),
            'top_raffles' => $this->topRaffles($from, $to),
            'waiting' => [
                'withdrawals' => WithdrawalRequest::query()->where('status', 'pending')->count(),
                'withdrawals_amount' => round((float) WithdrawalRequest::query()->where('status', 'pending')->sum('amount_to_send'), 2),
                'support_tickets' => SupportTicket::query()->where('status', 'open')->count(),
            ],
        ];
    }

    /**
     * Gemini's short summary of the figures, as safe HTML.
     *
     * @throws AiUnavailableException
     */
    public function summary(array $figures): string
    {
        $site = config('app.name');
        $system = "You are a business analyst for {$site}, a Nigerian online raffle and prize platform. "
            .'Write for the owner in clear, simple English. Use ₦ for naira. '
            .'Use only the numbers you are given; never invent figures, and say so when a number is too small to judge. '
            .'Remember that customers must only spend what they can afford: never suggest pressuring customers to spend more. '
            .'Format with simple HTML only (<h3>, <p>, <ul>, <li>, <strong>). No markdown, no styles, no scripts.';

        $prompt = "Period: {$figures['label']} ({$figures['from']} to {$figures['to']}, Lagos business).\n"
            .'Figures (this period, then the period before it, as JSON):'."\n"
            .json_encode(['this_period' => $figures['current'], 'period_before' => $figures['previous'], 'top_raffles' => $figures['top_raffles'], 'waiting_now' => $figures['waiting']], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)."\n\n"
            .'Keys: sales = money from ticket purchases; purchases = number of purchases; buyers = different customers who bought; tickets = tickets sold; '
            ."money_in = top-ups credited; paid_out = withdrawals paid; sign_ups = new accounts; errors = different site errors seen.\n\n"
            ."Write four short sections:\n"
            ."<h3>Summary</h3> 2 or 3 sentences on how the period went compared with the one before.\n"
            ."<h3>Going well</h3> a short list.\n"
            ."<h3>Needs attention</h3> a short list (drops, errors, waiting withdrawals or support tickets).\n"
            .'<h3>Next steps</h3> 3 specific, practical steps for the team.';

        return Tutorial::clean($this->gemini->generate('business-insights', $system, $prompt));
    }

    /** @return array<string, float|int> */
    private function totals(Carbon $from, Carbon $to): array
    {
        $sales = RaffleTransaction::query()->whereIn('type', self::SALE_TYPES)->where('status', 'verified_final')->whereBetween('created_at', [$from, $to]);

        return [
            'sales' => round((float) (clone $sales)->sum('claimed_amount'), 2),
            'purchases' => (clone $sales)->count(),
            'buyers' => (clone $sales)->distinct()->count('user_id'),
            'tickets' => RaffleEntry::query()->whereBetween('created_at', [$from, $to])->count(),
            'money_in' => round((float) WalletLedgerEntry::query()->where('direction', 'credit')->where('reason', 'deposit')->whereBetween('created_at', [$from, $to])->sum('amount'), 2),
            'paid_out' => round((float) WithdrawalRequest::query()->where('status', 'paid')->whereBetween('updated_at', [$from, $to])->sum('amount_to_send'), 2),
            'sign_ups' => WpUser::query()->whereBetween('user_registered', [$from, $to])->count(),
            'errors' => SiteError::query()->whereBetween('last_seen_at', [$from, $to])->count(),
        ];
    }

    /** @return list<array{title: string, tickets: int}> */
    private function topRaffles(Carbon $from, Carbon $to): array
    {
        $rows = RaffleEntry::query()
            ->whereBetween('created_at', [$from, $to])
            ->where('ticket_number', '>', 0)
            ->selectRaw('raffle_id, count(*) as n')
            ->groupBy('raffle_id')
            ->orderByDesc('n')
            ->limit(5)
            ->get();

        $titles = Raffle::query()->whereIn('public_id', $rows->pluck('raffle_id'))->pluck('title', 'public_id');

        return $rows->map(fn ($r) => ['title' => (string) ($titles[$r->raffle_id] ?? "Raffle #{$r->raffle_id}"), 'tickets' => (int) $r->n])->all();
    }

    /** @return array{0: Carbon, 1: Carbon, 2: Carbon, 3: Carbon} in UTC */
    private function window(string $period): array
    {
        $tz = config('raffles.timezone', 'Africa/Lagos');
        $now = now($tz);

        return match ($period) {
            // Today so far, against the same hours yesterday.
            'day' => [$now->copy()->startOfDay()->utc(), $now->copy()->utc(), $now->copy()->subDay()->startOfDay()->utc(), $now->copy()->subDay()->utc()],
            'week' => [$now->copy()->subDays(7)->utc(), $now->copy()->utc(), $now->copy()->subDays(14)->utc(), $now->copy()->subDays(7)->utc()],
            'month' => [$now->copy()->subDays(30)->utc(), $now->copy()->utc(), $now->copy()->subDays(60)->utc(), $now->copy()->subDays(30)->utc()],
            default => throw new \InvalidArgumentException('Unknown period.'),
        };
    }
}
