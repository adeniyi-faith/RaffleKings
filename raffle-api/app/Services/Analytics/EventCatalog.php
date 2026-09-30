<?php

namespace App\Services\Analytics;

use App\Http\Middleware\TrackApiActions;

/**
 * Every event the site records, in plain words, for System → Tracked Events.
 * An admin can switch any of them off; the server (Analytics::capture) and the
 * browser (lib/analytics.js, via the shared `analytics.disabled_events` prop)
 * both skip a switched-off event.
 *
 * To add an event to the site, record it as usual and add a line here so it
 * appears on the screen. An event missing from this list still works, it just
 * can't be switched off.
 */
final class EventCatalog
{
    public const BROWSER = 'browser';

    public const SERVER = 'server';

    /**
     * @return array<string, array<string, array{label: string, help: string, source: string}>> group → event key → details
     */
    public static function groups(): array
    {
        return [
            'Visits' => [
                '$pageview' => self::browser('Page views', 'Every page a visitor opens, including moves inside the site.'),
                '$autocapture' => self::browser('Automatic clicks', 'PostHog notes taps on buttons and links on its own. Switch off to record only the events listed here.'),
                'app_install_available' => self::browser('App install offered', 'The phone offered to install the app.'),
                'app_installed' => self::browser('App installed', 'Someone installed the app.'),
                'app_opened_from_home_screen' => self::browser('App opened from home screen', 'Someone opened the installed app.'),
            ],
            'Sign-up and login' => [
                'signup_started' => self::browser('Started signing up', 'Typed the first thing into the sign-up form.'),
                'signup_completed' => self::server('Finished signing up', 'The account was created. Includes whether they came from a referral.'),
                'signup_failed' => self::browser('Sign-up failed', 'The sign-up form was sent but rejected (no reason is recorded).'),
                'login_succeeded' => self::browser('Logged in', 'A successful login.'),
                'login_failed' => self::browser('Login failed', 'A wrong password or blocked login (no reason is recorded).'),
            ],
            'Buying tickets' => [
                'raffle_viewed' => self::browser('Raffle opened', 'Someone opened a raffle page.'),
                'buy_tickets_clicked' => self::browser('Tapped "Buy tickets"', 'How many tickets they picked, and whether they were logged in.'),
                'checkout_started' => self::browser('Picked numbers, went to checkout', 'Moved on from choosing numbers.'),
                'checkout_viewed' => self::browser('Reached the payment step', 'Opened the checkout page.'),
                'payment_failed' => self::browser('Ticket payment did not go through', 'Numbers taken, not enough money, or a limit. Only the error type is recorded.'),
                'tickets_purchased' => self::server('Tickets bought', 'A purchase that really went through: raffle, ticket count, amount and where the money came from.'),
                'golden_box_claimed' => self::server('Golden Box discount used', 'Someone claimed the Golden Box offer.'),
            ],
            'Wallet and money' => [
                'topup_started' => self::server('Top-up started', 'Someone asked to add money, with the amount.'),
                'topup_completed' => self::server('Top-up paid', 'The payment provider confirmed the money.'),
                'topup_failed' => self::server('Top-up could not start', 'No payment provider was available.'),
                'topup_payment_failed' => self::server('Top-up payment failed', 'The provider said the payment did not succeed.'),
                'topup_amount_mismatch' => self::server('Top-up paid wrong amount', 'The amount paid differed from the amount asked for.'),
                'winnings_moved_to_wallet' => self::server('Winnings moved to wallet', 'Someone transferred winnings into their wallet.'),
                'withdrawal_requested' => self::server('Withdrawal requested', 'Amount and fee.'),
                'withdrawal_paid' => self::server('Withdrawal paid', 'Staff confirmed the bank transfer.'),
                'withdrawal_rejected' => self::server('Withdrawal rejected', 'Staff declined and the money was returned.'),
                'bank_account_added' => self::server('Bank account added', 'That an account was added. No bank details are recorded.'),
            ],
            'Wins and referrals' => [
                'raffle_won' => self::server('Raffle won', 'Which raffle and the cash value of the prize.'),
                'referral_link_shared' => self::browser('Referral link shared', 'Copied or shared, and from which page.'),
                'referral_commission_earned' => self::server('Referral commission earned', 'A friend made their first top-up and the referrer was paid.'),
            ],
            'Rewards and games' => [
                'daily_reward_claimed' => self::server('Daily reward claimed', ''),
                'task_started' => self::server('Reward task started', ''),
                'task_claimed' => self::server('Reward task claimed', ''),
                'spin_played' => self::server('Spin & Win played', ''),
                'points_cashed_in' => self::server('Points cashed in', ''),
                'season_reward_claimed' => self::server('Season Pass reward claimed', ''),
                'prediction_answered' => self::server('Daily prediction answered', ''),
            ],
            'Community and live draw' => [
                'profile_link_shared' => self::browser('Profile link shared', 'A player copied or shared the link to their own profile, and from which page.'),
                'player_profile_viewed' => self::browser('Player profile opened', 'Someone opened another player\'s public profile card.'),
                'live_draw_viewed' => self::browser('Live draw opened', 'Someone opened a live draw page.'),
                'live_draw_comment_posted' => self::server('Live-draw comment posted', 'That a comment was posted. The message itself is not recorded.'),
                'red_envelope_sent' => self::server('Red envelope sent', ''),
                'red_envelope_claimed' => self::server('Red envelope claimed', ''),
                'unlock_link_created' => self::server('"Help me unlock" link created', ''),
                'unlock_link_tapped' => self::server('"Help me unlock" link tapped', ''),
                'team_created' => self::server('Team created', ''),
                'team_joined' => self::server('Team joined', ''),
                'winner_story_posted' => self::server('Winner story posted', ''),
            ],
            'Account and safety' => [
                'support_ticket_opened' => self::server('Support ticket opened', 'That a ticket was opened. The message is not recorded.'),
                'profile_privacy_changed' => self::server('Profile privacy changed', 'Someone changed who can see their profile or whether their wins show.'),
                'play_limit_changed' => self::server('Play limit changed', ''),
                'break_started' => self::server('Break from playing started', ''),
            ],
        ];
    }

    /** @return array<string, array{label: string, help: string, source: string}> */
    public static function all(): array
    {
        return array_merge(...array_values(self::groups()));
    }

    /** Event keys the admin has switched off. @return list<string> */
    public static function disabled(): array
    {
        return array_values(array_filter((array) config('services.analytics.disabled_events', []), 'is_string'));
    }

    public static function isDisabled(string $event): bool
    {
        return in_array($event, self::disabled(), true);
    }

    /** @return array{label: string, help: string, source: string} */
    private static function browser(string $label, string $help): array
    {
        return ['label' => $label, 'help' => $help, 'source' => self::BROWSER];
    }

    /** @return array{label: string, help: string, source: string} */
    private static function server(string $label, string $help): array
    {
        return ['label' => $label, 'help' => $help, 'source' => self::SERVER];
    }

    /** Events the reward/engagement middleware records that have no line above. @return list<string> */
    public static function unlisted(): array
    {
        return array_values(array_diff(array_values(TrackApiActions::EVENTS), array_keys(self::all())));
    }
}
