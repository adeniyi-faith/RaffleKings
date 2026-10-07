<?php

namespace App\Services\Admin;

use App\Filament\Resources\BroadcastResource;
use App\Models\Admin\CustomerTag;
use App\Models\Legacy\WpUser;
use App\Services\AdminAuditLogService;
use App\Services\UserManagementService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * What the Customers list can do to many customers at once (tick some, or
 * "select all" of a filtered list): tag or untag, ban or unban, message
 * them, or export them. Works through customers 500 at a time, and every
 * change is written to the audit log against each customer, the same as
 * doing it one by one.
 */
final class CustomerBulkActions
{
    /** Most customers one ban or unban can cover: a slip of the mouse can't ban the whole site. */
    public const MAX_BAN = 500;

    private const CHUNK = 500;

    /**
     * Add tags to every customer picked.
     *
     * @param  iterable<int>|array<int>  $ids
     * @param  list<string>  $tags
     * @return array{customers: int, added: int}  customers who got at least one new tag, and how many tags were added in all
     */
    public function addTags(WpUser $admin, iterable $ids, array $tags): array
    {
        $tags = $this->cleanTags($tags);
        $customers = 0;
        $added = 0;

        if ($tags === []) {
            return ['customers' => 0, 'added' => 0];
        }

        foreach ($this->chunks($ids) as $chunk) {
            $has = CustomerTag::query()->whereIn('user_id', $chunk)->get()->groupBy('user_id')
                ->map(fn ($rows) => $rows->pluck('tag')->map(fn ($t) => mb_strtolower($t))->all());
            $rows = [];

            foreach (WpUser::query()->whereIn('ID', $chunk)->pluck('ID') as $id) {
                $new = array_values(array_filter($tags, fn ($t) => ! in_array(mb_strtolower($t), $has[$id] ?? [], true)));

                foreach ($new as $tag) {
                    $rows[] = ['user_id' => $id, 'tag' => $tag, 'added_by' => $admin->ID, 'created_at' => now()];
                }

                if ($new !== []) {
                    $customers++;
                    $added += count($new);
                    $this->audit($admin, 'customer.tags_changed', $id, ['added' => $new, 'removed' => [], 'bulk' => true]);
                }
            }

            if ($rows !== []) {
                CustomerTag::query()->insertOrIgnore($rows);
            }
        }

        return ['customers' => $customers, 'added' => $added];
    }

    /**
     * Take tags off every customer picked.
     *
     * @param  iterable<int>|array<int>  $ids
     * @param  list<string>  $tags
     * @return array{customers: int, removed: int}
     */
    public function removeTags(WpUser $admin, iterable $ids, array $tags): array
    {
        $tags = $this->cleanTags($tags);
        $customers = 0;
        $removed = 0;

        if ($tags === []) {
            return ['customers' => 0, 'removed' => 0];
        }

        foreach ($this->chunks($ids) as $chunk) {
            $lower = array_map(fn ($t) => mb_strtolower($t), $tags);
            $found = CustomerTag::query()->whereIn('user_id', $chunk)->get()
                ->filter(fn (CustomerTag $t) => in_array(mb_strtolower($t->tag), $lower, true))->groupBy('user_id');

            foreach ($found as $userId => $rows) {
                CustomerTag::query()->whereIn('id', $rows->pluck('id'))->delete();
                $customers++;
                $removed += $rows->count();
                $this->audit($admin, 'customer.tags_changed', (int) $userId, ['added' => [], 'removed' => $rows->pluck('tag')->all(), 'bulk' => true]);
            }
        }

        return ['customers' => $customers, 'removed' => $removed];
    }

    /**
     * Ban the customers picked. Staff and the person clicking are never banned this way.
     *
     * @param  array<int>  $ids
     * @return array{banned: int, staff: int, already: int}
     */
    public function ban(WpUser $admin, array $ids, ?string $reason = null): array
    {
        return $this->setBanned($admin, $ids, true, $reason);
    }

    /**
     * @param  array<int>  $ids
     * @return array{banned: int, staff: int, already: int}  (banned = how many were changed; already = how many needed no change)
     */
    public function unban(WpUser $admin, array $ids): array
    {
        return $this->setBanned($admin, $ids, false, null);
    }

    private function setBanned(WpUser $admin, array $ids, bool $banned, ?string $reason): array
    {
        $ids = array_values(array_unique(array_map('intval', $ids)));

        if (count($ids) > self::MAX_BAN) {
            throw new RuntimeException('You picked '.number_format(count($ids)).' customers. Ban or unban at most '.self::MAX_BAN.' at a time, so a slip can\'t affect the whole site. Narrow the list with the filters first.');
        }

        $users = app(UserManagementService::class);
        $result = ['banned' => 0, 'staff' => 0, 'already' => 0];

        foreach ($this->chunks($ids) as $chunk) {
            foreach (WpUser::query()->whereIn('ID', $chunk)->get() as $user) {
                if ($user->ID === $admin->ID || $user->staffRole() !== null) {
                    $result['staff']++;

                    continue;
                }

                if ($user->isBanned() === $banned) {
                    $result['already']++;

                    continue;
                }

                if ($banned) {
                    $users->ban($admin, $user, $reason ?? 'Bulk ban from the Customers list');
                } elseif ($users->unban($admin, $user, 'Bulk unban from the Customers list') === 0) {
                    // A lift was already asked for: nothing more for this person to do.
                    $result['already']++;

                    continue;
                }

                $result['banned']++;
            }
        }

        return $result;
    }

    /**
     * Save the picked customers as a list the message form can pick up
     * (Message customers → "Customers ticked on the Customers list").
     *
     * @param  iterable<int>|array<int>  $ids
     * @return string the key to put in the message form's address (?list=…)
     */
    public function stashForMessage(iterable $ids): string
    {
        $token = Str::random(24);
        Cache::put(BroadcastResource::TICKED_CACHE_PREFIX.$token, collect($ids)->map(fn ($i) => (int) $i)->unique()->values()->all(), now()->addHours(3));

        return $token;
    }

    /**
     * Spreadsheet rows for the picked customers.
     *
     * @param  iterable<int>|array<int>  $ids
     * @return iterable<list<string|int|float|null>>
     */
    public function exportRows(iterable $ids): iterable
    {
        foreach ($this->chunks($ids) as $chunk) {
            $tags = CustomerTag::query()->whereIn('user_id', $chunk)->get()->groupBy('user_id');

            foreach (WpUser::query()->with('wallet')->whereIn('ID', $chunk)->orderBy('ID')->get() as $u) {
                yield [
                    $u->ID, $u->user_login, $u->display_name, $u->user_email,
                    $u->user_registered ? \Illuminate\Support\Carbon::parse($u->user_registered)->tz(config('raffles.timezone'))->format('Y-m-d') : '',
                    (float) ($u->wallet->wallet_balance ?? 0), (float) ($u->wallet->earnings_balance ?? 0),
                    $u->isBanned() ? 'Yes' : 'No',
                    isset($tags[$u->ID]) ? $tags[$u->ID]->pluck('tag')->implode(', ') : '',
                ];
            }
        }
    }

    /** @return list<string> */
    public static function exportHeadings(): array
    {
        return ['Customer #', 'Username', 'Name', 'Email', 'Joined', 'Wallet (NGN)', 'Winnings (NGN)', 'Banned', 'Tags'];
    }

    /** @return list<string> */
    private function cleanTags(array $tags): array
    {
        return collect($tags)->map(fn ($t) => CustomerTag::clean((string) $t))->filter()->unique(fn ($t) => mb_strtolower($t))->values()->all();
    }

    /** @return iterable<list<int>> */
    private function chunks(iterable $ids): iterable
    {
        $chunk = [];

        foreach ($ids as $id) {
            $chunk[] = (int) $id;

            if (count($chunk) >= self::CHUNK) {
                yield $chunk;
                $chunk = [];
            }
        }

        if ($chunk !== []) {
            yield $chunk;
        }
    }

    private function audit(WpUser $admin, string $action, int $customerId, array $context): void
    {
        app(AdminAuditLogService::class)->record($admin, $action, WpUser::class, $customerId, $context);
    }
}
