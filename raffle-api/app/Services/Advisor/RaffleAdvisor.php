<?php

namespace App\Services\Advisor;

use App\Exceptions\AiUnavailableException;
use App\Jobs\WriteAdvisorReport;
use App\Models\AdvisorReport;
use App\Models\Legacy\WpUser;
use App\Models\Raffle;
use App\Services\AdminAuditLogService;
use App\Services\Ai\ClaudeClient;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * The Raffle advisor: an expert adviser for the raffles team. It reads the
 * site's own totals (PlatformSnapshot), asks Claude for practical advice on
 * raffles, prizes, prices and offers, and saves the answer as a report.
 *
 * It only advises. The one thing it can do on the site is open one of its
 * suggested raffles as a DRAFT, when a staff member presses the button; a
 * person always reviews and publishes it.
 */
class RaffleAdvisor
{
    public const KINDS = [
        'new_raffle' => 'New raffle',
        'prize_structure' => 'Prize structure',
        'pricing' => 'Pricing',
        'incentive' => 'Incentive',
        'schedule' => 'Raffle calendar',
        'new_idea' => 'New idea',
    ];

    private const PRIZE_TYPES = ['cash', 'gadgets', 'vouchers', 'other'];

    public function __construct(
        private readonly ClaudeClient $claude,
        private readonly PlatformSnapshot $snapshot,
    ) {}

    /** Start a new report. It is written in the background (it can take a minute or two). */
    public function request(?string $focus = null, ?WpUser $by = null, string $trigger = 'manual'): AdvisorReport
    {
        $report = AdvisorReport::create([
            'status' => 'pending',
            'trigger' => $trigger,
            'requested_by' => $by?->ID,
            'focus' => filled($focus) ? Str::limit(trim($focus), 1000, '') : null,
        ]);

        WriteAdvisorReport::dispatch($report->id);

        return $report;
    }

    /** Write the report: gather the totals, ask Claude, save the answer. */
    public function write(AdvisorReport $report): AdvisorReport
    {
        $snapshot = $this->snapshot->build();
        $report->update(['snapshot' => $snapshot, 'model' => $this->claude->model()]);

        try {
            $answer = $this->claude->json('raffle-advisor', $this->system(), $this->prompt($snapshot, $report->focus), $this->schema());
        } catch (AiUnavailableException $e) {
            $report->update(['status' => 'failed', 'error' => Str::limit($e->getMessage(), 290)]);

            return $report;
        }

        $report->update([
            'status' => 'ready',
            'summary' => trim((string) ($answer['summary'] ?? '')),
            'recommendations' => collect($answer['recommendations'] ?? [])
                ->filter(fn ($r) => is_array($r) && filled($r['title'] ?? null))
                ->map(fn ($r) => $r + ['opened_raffle_id' => null])
                ->values()
                ->all(),
            'error' => null,
        ]);

        return $report;
    }

    /**
     * Open a recommended raffle as a draft, with its prize tiers and draw rules.
     * Never published here: staff check it and publish it themselves.
     *
     * @throws RuntimeException with a message safe to show staff
     */
    public function openAsDraft(AdvisorReport $report, int $index, WpUser $admin): Raffle
    {
        return DB::transaction(function () use ($report, $index, $admin) {
            $report = AdvisorReport::query()->lockForUpdate()->findOrFail($report->id);
            $recs = (array) $report->recommendations;
            $rec = $recs[$index] ?? null;

            if (! is_array($rec) || ! is_array($rec['raffle_draft'] ?? null)) {
                throw new RuntimeException('This suggestion has no ready-made raffle to open.');
            }

            if (! empty($rec['opened_raffle_id']) && Raffle::query()->whereKey($rec['opened_raffle_id'])->exists()) {
                throw new RuntimeException('This suggestion was already opened as a draft raffle.');
            }

            $d = $rec['raffle_draft'];
            $isFlash = (bool) ($d['is_flash'] ?? false);
            $tz = config('raffles.timezone', 'Africa/Lagos');

            $raffle = Raffle::create([
                'title' => Str::limit(trim((string) ($d['title'] ?? $rec['title'])), 250, ''),
                'excerpt' => Str::limit(trim((string) ($d['excerpt'] ?? '')), 2000, '') ?: null,
                'price' => max(1, round((float) ($d['ticket_price'] ?? 0), 2)),
                'max_tickets' => max(1, min(1_000_000, (int) ($d['tickets_available'] ?? 0))),
                'max_per_order' => ($m = (int) ($d['max_per_order'] ?? 0)) > 0 ? $m : null,
                'grand_prize' => Str::limit(trim((string) ($d['grand_prize'] ?? '')), 250, '') ?: null,
                'prize_type' => in_array($d['prize_type'] ?? null, self::PRIZE_TYPES, true) ? $d['prize_type'] : 'other',
                'is_flash' => $isFlash,
                'sales_end_at' => $isFlash ? now($tz)->addHours(max(1, min(72, (int) ($d['flash_hours'] ?? 1))))->utc() : null,
                'expiry' => $isFlash ? null : now($tz)->addDays(max(1, min(90, (int) ($d['sales_days'] ?? 7))))->toDateString(),
                'draw_rules' => $this->drawRules((array) ($d['draw_rules'] ?? [])),
                'status' => 'draft',
            ]);

            foreach (array_slice((array) ($d['prize_tiers'] ?? []), 0, 20) as $i => $tier) {
                if (! is_array($tier) || blank($tier['tier_name'] ?? null)) {
                    continue;
                }

                $raffle->prizeTiers()->create([
                    'tier_name' => Str::limit(trim((string) $tier['tier_name']), 250, ''),
                    'prize_description' => Str::limit(trim((string) ($tier['prize_description'] ?? '')), 250, '') ?: null,
                    'cash_value' => max(0, round((float) ($tier['cash_value'] ?? 0), 2)),
                    'winner_count' => max(1, min(10_000, (int) ($tier['winner_count'] ?? 1))),
                    'rank' => $i + 1,
                ]);
            }

            $recs[$index]['opened_raffle_id'] = $raffle->id;
            $report->update(['recommendations' => $recs]);

            app(AdminAuditLogService::class)->record($admin, 'raffle.opened_from_advice', Raffle::class, $raffle->id, [
                'advisor_report_id' => $report->id,
                'suggestion' => $rec['title'],
            ]);

            return $raffle;
        });
    }

    /** @return array<string, int|bool> */
    private function drawRules(array $r): array
    {
        return [
            'max_wins_per_person' => max(1, min(50, (int) ($r['max_wins_per_person'] ?? config('raffles.default_draw_rules.max_wins_per_person', 1)))),
            'new_players_only' => (bool) ($r['new_players_only'] ?? false),
            'loyalty_bonus_entries' => (bool) ($r['loyalty_bonus_entries'] ?? false),
            'consolation_min_tickets' => max(0, (int) ($r['consolation_min_tickets'] ?? 0)),
            'consolation_points' => max(0, (int) ($r['consolation_points'] ?? 0)),
        ];
    }

    private function system(): string
    {
        $site = config('app.name');
        $rules = trim((string) config('ai.instructions'));

        return <<<TXT
        You are the Raffle advisor for {$site}, a Nigerian online raffle and prize platform where players buy tickets in naira (₦). You advise the owner and the raffles team.

        You are an expert in raffles, lotteries, sweepstakes and prize promotions around the world, and in the psychology behind them: anticipation, near-misses, variable rewards, habit loops, social proof, scarcity and deadlines, loss aversion, fairness perception, and why people come back. You understand incentives, risk and reward on both sides: the platform must stay profitable, and players must feel the deal is fair and fun.

        Your job is to give practical, specific advice: which raffles to run next, prize structures, ticket prices and sizes, incentives, a calendar for the week, and fresh ideas the platform has never tried (for example a "daily drop" that randomly credits ticket holders from part of a raffle's pot every day, a weekly mega raffle beside small fast ones, team or streak rewards). Ideas outside what the site does today are welcome when they could grow the platform; mark them as needing to be built.

        What the site can already do (so you can say "ready today"):
        - Raffles with a ticket price, number of tickets, an optional per-order limit, a grand prize and prize tiers (each tier can have several winners and a cash value).
        - Prize types: cash, gadgets, vouchers, other.
        - Normal raffles that end on a chosen day, and flash raffles that stop selling at an exact time with a live countdown.
        - Draw rules: most prizes one person can win, a rest period for recent winners, new-players-only raffles, members-tier-only raffles, loyalty bonus entries, and consolation reward points for non-winners who bought at least N tickets.
        - Live draw events that viewers watch together, a Hall of Fame of winners, and winner stories.
        - Reward points, daily tasks, spin-the-wheel, a season pass, predictions, red envelopes (gifting points), referral rewards and promo codes.
        - Provably fair draws: the draw is locked in advance and anyone can check it.

        Hard rules:
        - Fair and transparent always. Never suggest hidden odds, rigged or adjusted draws, fake winners, fake scarcity, misleading countdowns, or anything that would not survive being explained to players.
        - Responsible play: never suggest pressuring people to spend more than they can afford, chasing losses, or targeting people who seem to be over-spending. Prefer incentives that reward coming back and having fun over ones that reward spending big.
        - Use only the numbers you are given. If the data is thin (few raffles or players), say so and keep suggestions small and testable. Never invent figures about this platform.
        - Keep the business sound: a raffle's cash prizes should leave a sensible margin if it sells well, and say what happens if it sells badly.
        - Write in clear, simple English that a busy owner can act on. No jargon; if you need a technical word, explain it.
        TXT.($rules !== '' ? "\n\nHouse rules from the team: {$rules}" : '');
    }

    private function prompt(array $snapshot, ?string $focus): string
    {
        return "Here are the platform's totals (JSON). Money is in naira. 'past_advice' shows what you suggested before and how anything we acted on actually sold: learn from it.\n\n"
            .json_encode($snapshot, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
            ."\n\n".($focus ? "The team asked specifically: {$focus}\n\n" : '')
            ."Give your advice:\n"
            ."- summary: 3 to 6 short sentences. What the numbers say about our players right now, and the one thing that matters most this week.\n"
            ."- recommendations: 3 to 6, most valuable first. Mix safe bets with at least one bolder idea. Each one must be specific (real prices, ticket counts, prizes, days).\n"
            ."- When a recommendation is a raffle we could open, fill raffle_draft with a complete raffle. Otherwise set raffle_draft to null.\n"
            .'- ready_today is true only if the site can already do it (see the list in your instructions).';
    }

    /** @return array<string, mixed> */
    private function schema(): array
    {
        $str = ['type' => 'string'];
        $int = ['type' => 'integer'];
        $bool = ['type' => 'boolean'];
        $obj = fn (array $props) => ['type' => 'object', 'properties' => $props, 'required' => array_keys($props), 'additionalProperties' => false];

        $draft = $obj([
            'title' => $str,
            'excerpt' => ['type' => 'string', 'description' => 'Honest, exciting description for the raffle page, under 600 characters.'],
            'ticket_price' => ['type' => 'number', 'description' => 'Naira per ticket.'],
            'tickets_available' => $int,
            'max_per_order' => ['type' => ['integer', 'null'], 'description' => 'Most tickets in one order, or null for the site limit.'],
            'grand_prize' => $str,
            'prize_type' => ['type' => 'string', 'enum' => self::PRIZE_TYPES],
            'is_flash' => $bool,
            'flash_hours' => ['type' => ['integer', 'null'], 'description' => 'For a flash raffle: hours of sales (1-72). Otherwise null.'],
            'sales_days' => ['type' => ['integer', 'null'], 'description' => 'For a normal raffle: days of sales (1-90). Otherwise null.'],
            'prize_tiers' => ['type' => 'array', 'description' => 'Grand prize first.', 'items' => $obj([
                'tier_name' => $str,
                'prize_description' => $str,
                'cash_value' => ['type' => 'number', 'description' => 'Naira value of ONE prize at this tier (0 if not cash).'],
                'winner_count' => $int,
            ])],
            'draw_rules' => $obj([
                'max_wins_per_person' => $int,
                'new_players_only' => $bool,
                'loyalty_bonus_entries' => $bool,
                'consolation_min_tickets' => $int,
                'consolation_points' => $int,
            ]),
        ]);

        return $obj([
            'summary' => $str,
            'recommendations' => ['type' => 'array', 'items' => $obj([
                'title' => ['type' => 'string', 'description' => 'Short headline, under 80 characters.'],
                'kind' => ['type' => 'string', 'enum' => array_keys(self::KINDS)],
                'ready_today' => $bool,
                'what' => ['type' => 'string', 'description' => 'What to do, specifically.'],
                'why' => ['type' => 'string', 'description' => 'Why it should work: the player behaviour or psychology, tied to our numbers.'],
                'steps' => ['type' => 'array', 'items' => $str],
                'risks' => ['type' => 'string', 'description' => 'What could go wrong, including the money risk if it sells badly.'],
                'fairness' => ['type' => 'string', 'description' => 'How to keep it fair and clear for players.'],
                'measure' => ['type' => 'string', 'description' => 'The number to watch to know if it worked.'],
                'raffle_draft' => ['anyOf' => [$draft, ['type' => 'null']]],
            ])],
        ]);
    }
}
