<?php

namespace App\Services\Engagement;

use App\Models\Legacy\RaffleEntry;
use App\Models\Legacy\RaffleTransaction;
use App\Models\Legacy\RaffleWinner;
use App\Models\Legacy\WpUser;
use App\Models\LuckyMeter as Meter;
use App\Models\LuckyMeterEvent;
use App\Models\Raffle;
use App\Models\Wallet;
use App\Notifications\LuckyMeterFilled;
use App\Services\WalletLedgerService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * The Lucky Meter: a reward a non-winner can count on (the "pity" idea from
 * Asian games, where every miss is progress). After a raffle's draw, the
 * money each customer paid for tickets in it fills their meter, unless they
 * won any prize in that raffle. Each time the meter reaches the target, it
 * pays a fixed amount of ticket credit (wallet balance: spendable on
 * tickets, not withdrawable), and whatever was left over stays on the meter.
 * A loss is never wasted, and the meter never goes backwards.
 *
 * Same rule for everyone, shown up front; the cost is a fixed share of what
 * non-winners spend (reward ÷ target). Only paid tickets count, never free
 * bonus entries, and a cancelled (refunded) raffle counts for nothing.
 *
 * Money safety: a raffle is marked counted (unique key) in the same
 * database transaction that fills the meters, so it can never be counted
 * twice, even if the draw hook and the safety-net sweep run together.
 */
class LuckyMeter
{
    public function __construct(private readonly WalletLedgerService $ledger) {}

    public static function enabled(): bool
    {
        return (bool) config('engagement.lucky_meter.enabled') && self::target() > 0 && self::reward() > 0 && self::reward() <= self::target();
    }

    public static function target(): float
    {
        return (float) config('engagement.lucky_meter.target', 0);
    }

    public static function reward(): float
    {
        return (float) config('engagement.lucky_meter.reward', 0);
    }

    /** Called right after a draw; never lets a meter problem break the draw. */
    public function afterDraw(Raffle $raffle): void
    {
        rescue(fn () => $this->countRaffle($raffle), report: true);
    }

    /**
     * Safety net (scheduler): count any raffle whose winners were picked in
     * the last few hours but which the draw hook missed (for example a draw
     * run by the old site). Only recent draws, so switching the meter on
     * never back-fills old raffles.
     */
    public function countRecentDraws(): int
    {
        if (! self::enabled()) {
            return 0;
        }

        $counted = 0;

        RaffleWinner::query()
            ->where('won_at', '>=', now()->subHours(6))
            ->distinct()
            ->pluck('raffle_id')
            ->each(function ($publicId) use (&$counted) {
                $raffle = Raffle::query()->where('public_id', $publicId)->first();

                try {
                    if ($raffle && $this->countRaffle($raffle)) {
                        $counted++;
                    }
                } catch (\Throwable $e) {
                    Log::error('Lucky Meter failed', ['raffle' => $raffle?->id, 'error' => $e->getMessage()]);
                }
            });

        return $counted;
    }

    /**
     * Fill every non-winner's meter from one drawn raffle. Returns false if
     * there was nothing to do (meter off, not drawn, cancelled, or already counted).
     */
    public function countRaffle(Raffle $raffle): bool
    {
        if (! self::enabled() || $raffle->cancelled_at || ! RaffleWinner::query()->where('raffle_id', $raffle->public_id)->exists()) {
            return false;
        }

        $target = self::target();
        $reward = self::reward();
        $paid = [];

        $counted = DB::transaction(function () use ($raffle, $target, $reward, &$paid) {
            // Claim the raffle first: a second run inserts nothing here and stops.
            if (DB::table('lucky_meter_raffles')->insertOrIgnore(['raffle_id' => $raffle->id, 'counted_at' => now()]) === 0) {
                return false;
            }

            $spend = $this->spendByNonWinners($raffle->public_id);
            $fills = 0;

            foreach ($spend as $userId => $amount) {
                $meter = Meter::query()->lockForUpdate()->find($userId) ?? Meter::create(['user_id' => $userId]);
                $progress = $meter->progress + $amount;
                LuckyMeterEvent::create(['user_id' => $userId, 'raffle_id' => $raffle->id, 'kind' => 'added', 'amount' => $amount]);

                $times = (int) floor($progress / $target);

                if ($times > 0) {
                    $this->payCredit($userId, $reward * $times, $raffle);
                    LuckyMeterEvent::create(['user_id' => $userId, 'raffle_id' => $raffle->id, 'kind' => 'paid', 'amount' => $reward * $times]);
                    $progress -= $target * $times;
                    $fills += $times;
                    $paid[$userId] = $reward * $times;
                }

                $meter->update([
                    'progress' => round($progress, 2),
                    'fills' => $meter->fills + $times,
                    'total_paid' => $meter->total_paid + $reward * $times,
                ]);
            }

            DB::table('lucky_meter_raffles')->where('raffle_id', $raffle->id)->update([
                'players' => count($spend),
                'amount_added' => round(array_sum($spend), 2),
                'fills' => $fills,
            ]);

            return true;
        });

        if (! $counted) {
            return false; // already counted
        }

        foreach ($paid as $userId => $amount) {
            if ($user = WpUser::query()->find($userId)) {
                rescue(fn () => $user->notify(new LuckyMeterFilled($amount)), report: true);
            }
        }

        return true;
    }

    /**
     * Naira each customer paid (verified orders only) for tickets in this
     * raffle, for everyone who won nothing in it.
     *
     * @return array<int, float> user id => amount
     */
    private function spendByNonWinners(int $publicId): array
    {
        $winners = RaffleWinner::query()->where('raffle_id', $publicId)->pluck('user_id')->map(fn ($id) => (int) $id)->all();

        $txnOwners = RaffleEntry::query()
            ->where('raffle_id', $publicId)
            ->where('txn_id', '>', 0)
            ->whereNotIn('user_id', $winners)
            ->distinct()
            ->get(['user_id', 'txn_id'])
            ->mapWithKeys(fn ($e) => [(int) $e->txn_id => (int) $e->user_id]);

        if ($txnOwners->isEmpty()) {
            return [];
        }

        $spend = [];

        RaffleTransaction::query()
            ->whereIn('id', $txnOwners->keys())
            ->where('status', 'verified_final')
            ->get(['id', 'claimed_amount'])
            ->each(function ($txn) use ($txnOwners, &$spend) {
                $userId = $txnOwners[(int) $txn->id];
                $spend[$userId] = ($spend[$userId] ?? 0) + (float) $txn->claimed_amount;
            });

        ksort($spend);

        return array_filter($spend, fn ($amount) => $amount > 0);
    }

    private function payCredit(int $userId, float $amount, Raffle $raffle): void
    {
        $wallet = Wallet::query()->where('user_id', $userId)->lockForUpdate()->first()
            ?? Wallet::create(['user_id' => $userId, 'wallet_balance' => 0, 'earnings_balance' => 0]);
        $wallet->wallet_balance = (float) $wallet->wallet_balance + $amount;
        $wallet->save();

        $this->ledger->recordCredit(
            userId: $userId,
            balanceType: 'wallet',
            amount: $amount,
            reason: 'lucky_meter',
            referenceType: 'raffle',
            referenceId: $raffle->id,
            description: "Lucky Meter filled (after {$raffle->title})",
        );
    }

    /** What the Rewards page shows: the rules, and (signed in) this customer's meter and history. */
    public function state(?int $userId): ?array
    {
        if (! self::enabled()) {
            return null;
        }

        $meter = $userId ? Meter::query()->find($userId) : null;
        $progress = (float) ($meter?->progress ?? 0);

        return [
            'target' => self::target(),
            'reward' => self::reward(),
            'progress' => $progress,
            'percent' => (int) min(100, floor($progress / self::target() * 100)),
            'fills' => (int) ($meter?->fills ?? 0),
            'total_paid' => (float) ($meter?->total_paid ?? 0),
            'recent' => $userId ? LuckyMeterEvent::query()->with('raffle:id,title')->where('user_id', $userId)->latest('id')->limit(5)->get()
                ->map(fn (LuckyMeterEvent $e) => [
                    'kind' => $e->kind,
                    'amount' => $e->amount,
                    'raffle' => $e->raffle?->title,
                    'date' => $e->created_at?->toIso8601String(),
                ])->all() : [],
        ];
    }
}
