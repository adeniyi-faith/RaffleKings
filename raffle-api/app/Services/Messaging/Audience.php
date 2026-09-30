<?php

namespace App\Services\Messaging;

use App\Models\Admin\CustomerTag;
use App\Models\Growth\CustomerSource;
use App\Models\Legacy\RaffleEntry;
use App\Models\Legacy\WpUser;
use App\Models\Legacy\WpUserMeta;
use App\Models\Wallet;
use App\Models\WalletLedgerEntry;
use App\Services\Reminders\ReminderService;
use Illuminate\Database\Eloquent\Builder;
use InvalidArgumentException;

/**
 * Who a message goes to. Banned customers are always left out, and so is
 * staff (they aren't the audience for customer promotions). A promotion
 * also leaves out everyone who tapped "stop reminders".
 *
 * "custom" combines any of the FILTERS below: every filter that's filled
 * in must match (e.g. joined in the last 30 days AND never bought AND
 * tagged VIP). "selected" is an exact list of customers, from ticking
 * them on the Customers list.
 */
final class Audience
{
    public const TYPES = [
        'everyone' => 'Every customer',
        'raffle_buyers' => 'Everyone who bought tickets in a raffle',
        'inactive' => 'Customers who haven\'t bought tickets for a while',
        'never_bought' => 'Signed up but never bought a ticket',
        'wallet_balance' => 'Money sitting in their wallet',
        'unwithdrawn_winnings' => 'Winnings they haven\'t withdrawn',
        'one' => 'One customer',
    ];

    /**
     * Understood by query() but not offered on the message form yet: they
     * need their own fields (filters, or the ticked customers).
     */
    public const UPCOMING_TYPES = [
        'custom' => 'Build my own group (combine filters)',
        'selected' => 'Customers ticked on the Customers list',
    ];

    /** The filters "custom" can combine, and how each is described. */
    public const FILTERS = [
        'joined_within_days' => 'Joined in the last N days',
        'joined_before_days' => 'Joined more than N days ago',
        'played_within_days' => 'Bought tickets in the last N days',
        'not_played_for_days' => 'Has played before, but not in the last N days',
        'never_bought' => 'Never bought a ticket',
        'min_wallet' => 'Spending wallet at least ₦',
        'min_winnings' => 'Winnings at least ₦',
        'min_spent' => 'Spent at least ₦ on tickets in total',
        'raffle_id' => 'Bought tickets in raffle',
        'tags' => 'Has any of these tags',
        'exclude_tags' => 'Doesn\'t have any of these tags',
        'promo_code_id' => 'Signed up with promo code',
        'affiliate_id' => 'Brought by affiliate',
        'push_only' => 'Turned on phone notifications',
    ];

    /** @param  array<string, mixed>  $options */
    public function query(string $type, array $options = [], bool $isPromotion = false): Builder
    {
        $query = match ($type) {
            'everyone' => WpUser::query(),
            'custom' => $this->custom((array) ($options['filters'] ?? [])),
            'raffle_buyers' => WpUser::query()->whereIn('ID', RaffleEntry::query()->where('raffle_id', (int) ($options['raffle_id'] ?? 0))->select('user_id')),
            'inactive' => WpUser::query()->whereNotIn('ID', RaffleEntry::query()->where('created_at', '>=', now()->subDays(max(1, (int) ($options['days'] ?? 30))))->select('user_id'))
                ->whereIn('ID', RaffleEntry::query()->select('user_id')),
            'never_bought' => WpUser::query()->whereNotIn('ID', RaffleEntry::query()->select('user_id')),
            'wallet_balance' => WpUser::query()->whereIn('ID', Wallet::query()->where('wallet_balance', '>=', max(1, (float) ($options['min_amount'] ?? 100)))->select('user_id')),
            'unwithdrawn_winnings' => WpUser::query()->whereIn('ID', Wallet::query()->where('earnings_balance', '>=', max(1, (float) ($options['min_amount'] ?? 100)))->select('user_id')),
            'one' => WpUser::query()->whereKey((int) ($options['user_id'] ?? 0)),
            'selected' => WpUser::query()->whereIn('ID', array_map('intval', (array) ($options['user_ids'] ?? []))),
            default => throw new InvalidArgumentException("Unknown audience {$type}"),
        };

        $prefix = config('legacy.wp_prefix');

        return $query
            ->whereNotIn('ID', WpUserMeta::query()->where('meta_key', 'rk_is_banned')->where('meta_value', '1')->select('user_id'))
            ->when(! in_array($type, ['one', 'selected'], true), fn ($q) => $q->whereNotIn('ID', WpUserMeta::query()->where('meta_key', $prefix.'capabilities')->where('meta_value', 'like', '%"administrator"%')->select('user_id')))
            ->when($isPromotion, fn ($q) => $q->whereNotIn('ID', WpUserMeta::query()->where('meta_key', ReminderService::OPT_OUT_META)->where('meta_value', '1')->select('user_id')));
    }

    /** @param  array<string, mixed>  $f */
    private function custom(array $f): Builder
    {
        $q = WpUser::query();
        $n = fn (string $key) => filled($f[$key] ?? null) ? (float) $f[$key] : null;

        if ($days = $n('joined_within_days')) {
            $q->where('user_registered', '>=', now()->subDays((int) $days));
        }
        if ($days = $n('joined_before_days')) {
            $q->where('user_registered', '<', now()->subDays((int) $days));
        }
        if ($days = $n('played_within_days')) {
            $q->whereIn('ID', RaffleEntry::query()->where('created_at', '>=', now()->subDays((int) $days))->select('user_id'));
        }
        if ($days = $n('not_played_for_days')) {
            $q->whereIn('ID', RaffleEntry::query()->select('user_id'))
                ->whereNotIn('ID', RaffleEntry::query()->where('created_at', '>=', now()->subDays((int) $days))->select('user_id'));
        }
        if (! empty($f['never_bought'])) {
            $q->whereNotIn('ID', RaffleEntry::query()->select('user_id'));
        }
        if ($amount = $n('min_wallet')) {
            $q->whereIn('ID', Wallet::query()->where('wallet_balance', '>=', $amount)->select('user_id'));
        }
        if ($amount = $n('min_winnings')) {
            $q->whereIn('ID', Wallet::query()->where('earnings_balance', '>=', $amount)->select('user_id'));
        }
        if ($amount = $n('min_spent')) {
            $q->whereIn('ID', WalletLedgerEntry::query()->where('reason', 'ticket_purchase')->where('direction', 'debit')
                ->groupBy('user_id')->havingRaw('SUM(amount) >= ?', [$amount])->select('user_id'));
        }
        if ($raffle = $n('raffle_id')) {
            $q->whereIn('ID', RaffleEntry::query()->where('raffle_id', (int) $raffle)->select('user_id'));
        }
        if ($tags = array_filter((array) ($f['tags'] ?? []))) {
            $q->whereIn('ID', CustomerTag::query()->whereIn('tag', $tags)->select('user_id'));
        }
        if ($tags = array_filter((array) ($f['exclude_tags'] ?? []))) {
            $q->whereNotIn('ID', CustomerTag::query()->whereIn('tag', $tags)->select('user_id'));
        }
        if ($id = $n('promo_code_id')) {
            $q->whereIn('ID', CustomerSource::query()->where('promo_code_id', (int) $id)->select('user_id'));
        }
        if ($id = $n('affiliate_id')) {
            $q->whereIn('ID', CustomerSource::query()->where('affiliate_id', (int) $id)->select('user_id'));
        }
        if (! empty($f['push_only'])) {
            $q->whereIn('ID', WpUserMeta::query()->where('meta_key', 'rk_onesignal_id')->where('meta_value', '!=', '')->select('user_id'));
        }

        return $q;
    }

    /** @param  array<string, mixed>  $options */
    public function count(string $type, array $options = [], bool $isPromotion = false): int
    {
        return $this->query($type, $options, $isPromotion)->count();
    }

    /** @param  array<string, mixed>  $options */
    public function describe(string $type, array $options = []): string
    {
        return match ($type) {
            'raffle_buyers' => 'Bought tickets in raffle #'.($options['raffle_id'] ?? '?'),
            'inactive' => 'No tickets in the last '.($options['days'] ?? 30).' days',
            'wallet_balance' => 'Wallet at least ₦'.number_format((float) ($options['min_amount'] ?? 100)),
            'unwithdrawn_winnings' => 'Winnings at least ₦'.number_format((float) ($options['min_amount'] ?? 100)),
            'one' => 'Customer: '.(WpUser::find($options['user_id'] ?? 0)?->user_email ?? '?'),
            'selected' => count((array) ($options['user_ids'] ?? [])).' customers ticked on the Customers list',
            'custom' => $this->describeFilters((array) ($options['filters'] ?? [])),
            default => self::TYPES[$type] ?? self::UPCOMING_TYPES[$type] ?? $type,
        };
    }

    /** @param  array<string, mixed>  $f */
    private function describeFilters(array $f): string
    {
        $parts = [];

        foreach (self::FILTERS as $key => $label) {
            $value = $f[$key] ?? null;

            if (blank($value) || $value === false || $value === []) {
                continue;
            }

            $parts[] = match (true) {
                $value === true || $value === 1 || $value === '1' && in_array($key, ['never_bought', 'push_only'], true) => $label,
                is_array($value) => $label.': '.implode(', ', $value),
                str_contains($label, 'N days') => str_replace('N', (string) (int) $value, $label),
                str_ends_with($label, '₦') => $label.number_format((float) $value),
                default => $label.' #'.$value,
            };
        }

        return $parts === [] ? 'Every customer' : implode(' AND ', $parts);
    }
}
