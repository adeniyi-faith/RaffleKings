<?php

namespace App\Services;

use App\Exceptions\TicketUnavailableException;
use App\Models\Legacy\RaffleEntry;
use App\Models\NumberHold;
use Carbon\CarbonInterface;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Keeps a player's picked numbers for them for a few minutes (10 by default)
 * while they sign in and pay, so nobody else can pay for those numbers in
 * the meantime.
 *
 * A hold does NOT sell a number. `raffle_entries` (unique on raffle +
 * number) stays the only record of what is sold; a hold only stops OTHER
 * people from paying for the number while it is held.
 *
 * Who a hold belongs to:
 *  - a signed-in customer (user_id), or
 *  - a guest's browser (guest_token: a random label the browser keeps).
 *    When the guest signs in and comes back to checkout, claim() hands
 *    their held numbers to their account, with the original deadline.
 *
 * Rules worth knowing:
 *  - All or nothing: if any number is sold or held by someone else, nothing
 *    is held and the caller is told which numbers to replace.
 *  - The clock is never restarted by asking again. Once a hold has run out
 *    the numbers are free for anyone; asking again gives a fresh window only
 *    if they are still free.
 *  - TicketPurchaseService refuses to take payment for numbers someone else
 *    holds (assertNotHeldByOthers), so a hold cannot be bypassed.
 */
class NumberHoldService
{
    public function seconds(): int
    {
        return max(60, (int) config('holds.minutes', 10) * 60);
    }

    /** A guest token is a random string made by the browser; accept only a safe shape. */
    public static function cleanToken(mixed $token): ?string
    {
        return is_string($token) && preg_match('/^[A-Za-z0-9_-]{16,64}$/', $token) === 1 ? $token : null;
    }

    /**
     * Hold these numbers for this player.
     *
     * @param  int[]  $numbers
     * @return array{ok: bool, sold: int[], held: int[], too_many: bool, expires_at: ?CarbonInterface}
     */
    public function claim(int $raffleId, array $numbers, ?int $userId, ?string $guestToken): array
    {
        $numbers = array_values(array_unique(array_map('intval', $numbers)));
        $guestToken = self::cleanToken($guestToken);
        $failure = ['ok' => false, 'sold' => [], 'held' => [], 'too_many' => false, 'expires_at' => null];

        if ($numbers === [] || (! $userId && ! $guestToken)) {
            return $failure;
        }

        try {
            return DB::transaction(function () use ($raffleId, $numbers, $userId, $guestToken, $failure) {
                NumberHold::query()->where('expires_at', '<=', now())->delete();

                // Hand a guest's holds to the account that just signed in.
                if ($userId && $guestToken) {
                    NumberHold::query()
                        ->where('guest_token', $guestToken)
                        ->whereNull('user_id')
                        ->update(['user_id' => $userId, 'guest_token' => null]);
                }

                // Lock the rows we care about so two people can't both win one number.
                $rows = NumberHold::query()
                    ->where('raffle_id', $raffleId)
                    ->whereIn('ticket_number', $numbers)
                    ->lockForUpdate()
                    ->get();

                $mine = $rows->filter(fn (NumberHold $h) => $this->isMine($h, $userId, $guestToken))->keyBy('ticket_number');
                $held = $rows->reject(fn (NumberHold $h) => $this->isMine($h, $userId, $guestToken))->pluck('ticket_number')->map(fn ($n) => (int) $n)->all();
                $sold = $this->soldAmong($raffleId, $numbers);

                if ($sold !== [] || $held !== []) {
                    return [...$failure, 'sold' => $sold, 'held' => $held];
                }

                $newNumbers = array_values(array_diff($numbers, $mine->keys()->map(fn ($n) => (int) $n)->all()));

                if ($this->liveHoldsOf($userId, $guestToken) + count($newNumbers) > (int) config('holds.max_per_owner', 100)) {
                    return [...$failure, 'too_many' => true];
                }

                $deadline = now()->addSeconds($this->seconds());

                if ($newNumbers !== []) {
                    $now = now();
                    NumberHold::query()->insert(array_map(fn (int $n) => [
                        'raffle_id' => $raffleId,
                        'ticket_number' => $n,
                        'user_id' => $userId,
                        'guest_token' => $userId ? null : $guestToken,
                        'expires_at' => $deadline,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ], $newNumbers));
                }

                // The whole basket runs out when its earliest number does.
                $earliest = $mine->min('expires_at');

                return [
                    'ok' => true,
                    'sold' => [],
                    'held' => [],
                    'too_many' => false,
                    'expires_at' => $earliest && $earliest->lt($deadline) ? $earliest : $deadline,
                ];
            });
        } catch (UniqueConstraintViolationException) {
            // Someone claimed one of these numbers in the same instant.
            return [...$failure, 'held' => $this->heldByOthers($raffleId, $userId, $guestToken, $numbers)];
        }
    }

    /**
     * Numbers other people are holding right now.
     *
     * @param  int[]|null  $only  look only at these numbers (null = the whole raffle)
     * @return int[]
     */
    public function heldByOthers(int $raffleId, ?int $userId, ?string $guestToken, ?array $only = null): array
    {
        $guestToken = self::cleanToken($guestToken);

        return NumberHold::query()
            ->where('raffle_id', $raffleId)
            ->where('expires_at', '>', now())
            ->when($only !== null, fn ($q) => $q->whereIn('ticket_number', $only))
            ->get()
            ->reject(fn (NumberHold $h) => $this->isMine($h, $userId, $guestToken))
            ->pluck('ticket_number')
            ->map(fn ($n) => (int) $n)
            ->values()
            ->all();
    }

    /**
     * Called by TicketPurchaseService before any money moves.
     *
     * @param  int[]  $numbers
     *
     * @throws TicketUnavailableException
     */
    public function assertNotHeldByOthers(int $raffleId, array $numbers, int $userId): void
    {
        $held = $this->heldByOthers($raffleId, $userId, null, $numbers);

        if ($held !== []) {
            sort($held);

            throw new TicketUnavailableException($held, held: true);
        }
    }

    /**
     * Let go of numbers this player is holding (paid for, or changed their mind).
     *
     * @param  int[]  $numbers
     */
    public function release(int $raffleId, array $numbers, ?int $userId, ?string $guestToken): void
    {
        $guestToken = self::cleanToken($guestToken);

        if ($numbers === [] || (! $userId && ! $guestToken)) {
            return;
        }

        NumberHold::query()
            ->where('raffle_id', $raffleId)
            ->whereIn('ticket_number', array_map('intval', $numbers))
            ->where(fn ($q) => $userId
                ? $q->where('user_id', $userId)
                : $q->where('guest_token', $guestToken)->whereNull('user_id'))
            ->delete();
    }

    /** Housekeeping for the scheduler: delete holds that have run out. */
    public function pruneExpired(): int
    {
        return NumberHold::query()->where('expires_at', '<=', now())->delete();
    }

    private function isMine(NumberHold $hold, ?int $userId, ?string $guestToken): bool
    {
        if ($userId) {
            return (int) $hold->user_id === $userId;
        }

        return $guestToken !== null && $hold->user_id === null && $hold->guest_token === $guestToken;
    }

    private function liveHoldsOf(?int $userId, ?string $guestToken): int
    {
        return NumberHold::query()
            ->where('expires_at', '>', now())
            ->where(fn ($q) => $userId
                ? $q->where('user_id', $userId)
                : $q->where('guest_token', $guestToken)->whereNull('user_id'))
            ->count();
    }

    /**
     * @param  int[]  $numbers
     * @return int[]
     */
    private function soldAmong(int $raffleId, array $numbers): array
    {
        /** @var Collection<int, int> $sold */
        $sold = RaffleEntry::query()
            ->where('raffle_id', $raffleId)
            ->whereIn('ticket_number', $numbers)
            ->pluck('ticket_number');

        return $sold->map(fn ($n) => (int) $n)->values()->all();
    }
}
