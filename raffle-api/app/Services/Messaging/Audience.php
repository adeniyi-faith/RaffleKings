<?php

namespace App\Services\Messaging;

use App\Models\Legacy\RaffleEntry;
use App\Models\Legacy\WpUser;
use App\Models\Legacy\WpUserMeta;
use App\Models\Wallet;
use Illuminate\Database\Eloquent\Builder;
use InvalidArgumentException;

/**
 * Who a message goes to. Banned customers are always left out, and so is
 * staff (they aren't the audience for customer promotions).
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

    /** @param  array<string, mixed>  $options */
    public function query(string $type, array $options = []): Builder
    {
        $query = match ($type) {
            'everyone' => WpUser::query(),
            'raffle_buyers' => WpUser::query()->whereIn('ID', RaffleEntry::query()->where('raffle_id', (int) ($options['raffle_id'] ?? 0))->select('user_id')),
            'inactive' => WpUser::query()->whereNotIn('ID', RaffleEntry::query()->where('created_at', '>=', now()->subDays(max(1, (int) ($options['days'] ?? 30))))->select('user_id'))
                ->whereIn('ID', RaffleEntry::query()->select('user_id')),
            'never_bought' => WpUser::query()->whereNotIn('ID', RaffleEntry::query()->select('user_id')),
            'wallet_balance' => WpUser::query()->whereIn('ID', Wallet::query()->where('wallet_balance', '>=', max(1, (float) ($options['min_amount'] ?? 100)))->select('user_id')),
            'unwithdrawn_winnings' => WpUser::query()->whereIn('ID', Wallet::query()->where('earnings_balance', '>=', max(1, (float) ($options['min_amount'] ?? 100)))->select('user_id')),
            'one' => WpUser::query()->whereKey((int) ($options['user_id'] ?? 0)),
            default => throw new InvalidArgumentException("Unknown audience {$type}"),
        };

        $prefix = config('legacy.wp_prefix');

        return $query
            ->whereNotIn('ID', WpUserMeta::query()->where('meta_key', 'rk_is_banned')->where('meta_value', '1')->select('user_id'))
            ->when($type !== 'one', fn ($q) => $q->whereNotIn('ID', WpUserMeta::query()->where('meta_key', $prefix.'capabilities')->where('meta_value', 'like', '%"administrator"%')->select('user_id')));
    }

    /** @param  array<string, mixed>  $options */
    public function count(string $type, array $options = []): int
    {
        return $this->query($type, $options)->count();
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
            default => self::TYPES[$type] ?? $type,
        };
    }
}
