<?php

namespace App\Support;

/**
 * Fills the {{placeholders}} in help guides and Knowledge base articles with the
 * site's CURRENT settings, so a guide that says "the smallest withdrawal is
 * {{min_withdrawal}}" is still right after staff change the number in Settings.
 * Text with no placeholders passes through untouched.
 */
class GuideTokens
{
    public static function fill(?string $text): string
    {
        $text = (string) $text;

        if (! str_contains($text, '{{')) {
            return $text;
        }

        $values = self::values();

        return preg_replace_callback('/\{\{\s*([a-z0-9_]+)\s*\}\}/', fn (array $m) => $values[$m[1]] ?? $m[0], $text);
    }

    /** @return array<string, string> */
    public static function values(): array
    {
        $naira = fn ($amount) => '₦'.number_format((float) $amount);
        $number = fn ($amount) => number_format((float) $amount);

        $daily = array_values((array) config('rewards.daily_claim', [50, 70, 100, 150, 200, 300, 1000]));
        $tasks = (array) config('rewards.tasks', []);
        $spinMax = collect((array) config('rewards.spin_prizes', []))->max('payout') ?? 500;
        $bundles = collect((array) config('pricing.bundles', []))->map(fn ($b) => $b['quantity'].' tickets for '.rtrim(rtrim(number_format((float) $b['percent_off'], 1), '0'), '.').'% off')->implode(', ');
        $ladder = collect((array) config('engagement.referral_ladder', []));

        return [
            'support_email' => (string) config('site.support_email', 'help@rafflekings.com.ng'),
            'telegram_support' => (string) (config('site.links.telegram_support') ?: 'our Telegram support channel'),

            'welcome_bonus' => $naira(300),
            'min_deposit' => $naira(config('payments.minimum_deposit', 100)),
            'min_withdrawal' => $naira(config('withdrawals.minimum_amount', 2000)),
            'verify_fee' => $naira(config('withdrawals.verification_fee', 1000)),
            'verify_threshold' => $naira(config('withdrawals.verification_deposit_threshold', 1000)),
            'auto_payout_max' => $naira(config('withdrawals.auto_payout_max', 200000)),
            'max_bank_accounts' => '2',
            'reset_code_minutes' => '15',

            'hold_minutes' => (string) (int) config('holds.minutes', 10),
            'max_held_numbers' => $number(config('holds.max_per_owner', 100)),
            'bundles' => $bundles,
            'cheap_ticket_max' => $naira(config('pricing.cheap_ticket_max_price', 200)),
            'cheap_bulk_percent' => $number(config('pricing.cheap_bulk_percent_off', 10)),
            'golden_percent' => $number(config('pricing.golden_box_percent_off', 10)),
            'golden_minimum' => $naira(config('pricing.golden_box_minimum_order', 1000)),
            'golden_offer_minutes' => $number(config('pricing.golden_box_offer_minutes', 30)),
            'golden_claim_minutes' => $number(config('pricing.golden_box_claim_minutes', 25)),
            'golden_cooldown_days' => $number(config('pricing.golden_box_cooldown_days', 7)),
            'recent_winner_days' => $number(config('raffles.default_draw_rules.recent_winner_cooldown_days', 3)),
            'list_closed_days' => $number(config('raffles.list_closed_for_days', 14)),

            'daily_points' => count($daily) > 1 ? implode(', ', array_map($number, array_slice($daily, 0, -1))).' and '.$number(end($daily)) : $number($daily[0] ?? 0),
            'daily_day1' => $number($daily[0] ?? 50),
            'daily_day7' => $number($daily[6] ?? 1000),
            'task_notifications' => $number($tasks['push_notification'] ?? 1500),
            'task_community' => $number($tasks['join_community'] ?? 1300),
            'task_whatsapp_follow' => $number($tasks['whatsapp_follow'] ?? 800),
            'task_whatsapp_share' => $number($tasks['whatsapp_share'] ?? 500),
            'task_wait_seconds' => $number(config('rewards.task_wait_seconds', 10)),
            'spin_cost' => $number(config('rewards.spin_cost', 50)),
            'spin_top_prize' => $number($spinMax),
            'points_per_naira' => $number(config('rewards.points_per_naira', 10)),
            'min_redeem_points' => $number(config('rewards.minimum_redeem_points', 100)),

            'referral_commission' => rtrim(rtrim(number_format((float) config('referrals.commission_rate', 0.5) * 100, 1), '0'), '.').'%',
            'ladder_first_points' => $number($ladder->first()['points'] ?? 200),
            'season_levels' => $number(config('engagement.season.levels', 30)),
            'season_weeks' => $number(config('engagement.season.weeks', 4)),
            'season_xp_per_level' => $number(config('engagement.season.xp_per_level', 100)),
            'xp_ticket' => $number(config('engagement.season.xp.ticket', 10)),
            'xp_ticket_cap' => $number(config('engagement.season.xp.ticket_daily_cap', 150)),
            'xp_daily_claim' => $number(config('engagement.season.xp.daily_claim', 25)),
            'xp_task' => $number(config('engagement.season.xp.task', 15)),
            'prediction_points' => $number(config('engagement.predictions.default_points', 50)),
            'team_size' => $number(config('engagement.teams.size', 3)),
            'team_hours' => $number(config('engagement.teams.hours', 24)),
            'unlock_taps' => $number(config('engagement.unlock.taps_needed', 3)),
            'story_points' => $number(config('engagement.stories.points', 100)),
            'envelope_min' => $number(config('engagement.red_envelopes.min_points', 50)),
            'envelope_max' => $number(config('engagement.red_envelopes.max_points', 5000)),
            'loyalty_weeks' => $number(config('loyalty.window_weeks', 8)),
        ];
    }
}
