<?php

namespace App\Services\Growth;

use App\Models\Growth\CustomerSource;
use App\Models\Growth\PromoCode;
use App\Models\Growth\PromoRedemption;
use App\Models\Legacy\RaffleEntry;
use App\Models\Legacy\WpUser;
use App\Models\Legacy\WpUserMeta;
use App\Models\Wallet;
use App\Services\PointsService;
use App\Services\WalletLedgerService;
use App\Support\Features;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Throwable;

/**
 * Promo codes (Settings → On / off → New features; Growth → Promo codes).
 *
 * Three kinds:
 *  - ticket_discount: % off an order at checkout, taken off after any bulk
 *    or Golden Box discount, optionally capped. The price quote and the
 *    purchase both ask quote() here, so the shown and charged prices agree.
 *  - welcome_bonus:   naira into the SPENDING wallet at sign-up (it can be
 *    played, never withdrawn).
 *  - welcome_points:  points at sign-up.
 *
 * Tracking: a code typed at sign-up (or in a ?promo= link) is saved as
 * the customer's source (customer_sources), so Growth → Promo codes can
 * show how many customers each code brought and what they spent. A
 * discount code given at sign-up is also remembered and filled in at
 * their first checkout.
 */
class PromoCodeService
{
    public const SAVED_CODE_META = 'rk_saved_promo';

    public function __construct(
        private readonly WalletLedgerService $ledger,
        private readonly PointsService $points,
    ) {}

    public static function normalise(?string $code): string
    {
        return strtoupper(preg_replace('/\s+/', '', (string) $code));
    }

    /** A live code by its text, or null. */
    public function find(?string $code): ?PromoCode
    {
        $code = self::normalise($code);

        if ($code === '' || ! Features::on('promo_codes')) {
            return null;
        }

        return PromoCode::query()
            ->where('code', $code)
            ->where('is_active', true)
            ->where(fn ($q) => $q->whereNull('starts_at')->orWhere('starts_at', '<=', now()))
            ->where(fn ($q) => $q->whereNull('ends_at')->orWhere('ends_at', '>', now()))
            ->first();
    }

    /**
     * Checks a code typed on the sign-up form, before the account exists.
     *
     * @throws InvalidArgumentException with a plain reason
     */
    public function assertUsableAtSignup(?string $code): ?PromoCode
    {
        if (self::normalise($code) === '') {
            return null;
        }

        $promo = $this->find($code) ?? throw new InvalidArgumentException('That promo code isn\'t valid or has ended.');
        $this->assertUsesLeft($promo);

        return $promo;
    }

    /**
     * A new customer signed up with this code: remember where they came
     * from, and give a welcome bonus/points now. Never breaks the sign-up.
     */
    public function applyAtSignup(WpUser $user, PromoCode $promo): void
    {
        try {
            CustomerSource::query()->firstOrCreate(['user_id' => $user->ID], ['promo_code_id' => $promo->id, 'affiliate_id' => $promo->affiliate_id]);

            if ($promo->kind === 'ticket_discount') {
                WpUserMeta::query()->updateOrCreate(['user_id' => $user->ID, 'meta_key' => self::SAVED_CODE_META], ['meta_value' => $promo->code]);

                return;
            }

            DB::transaction(function () use ($user, $promo) {
                $locked = PromoCode::query()->whereKey($promo->id)->lockForUpdate()->first();
                $this->assertUsesLeft($locked);

                $amount = (float) $promo->bonus_amount;

                if ($amount <= 0) {
                    return;
                }

                if ($promo->kind === 'welcome_bonus') {
                    $wallet = Wallet::query()->where('user_id', $user->ID)->lockForUpdate()->first()
                        ?? Wallet::create(['user_id' => $user->ID, 'wallet_balance' => 0, 'earnings_balance' => 0]);
                    $wallet->wallet_balance = (float) $wallet->wallet_balance + $amount;
                    $wallet->save();

                    $this->ledger->recordCredit($user->ID, 'wallet', $amount, 'promo_bonus', 'promo_code', $promo->id, "Promo code {$promo->code}");
                } else {
                    $this->points->credit($user, (int) $amount, 'promo_bonus', 'promo_code', $promo->id, "Promo code {$promo->code}");
                }

                PromoRedemption::create(['promo_code_id' => $promo->id, 'user_id' => $user->ID, 'context' => 'signup', 'value' => $amount]);
            });
        } catch (Throwable $e) {
            report($e);
        }
    }

    /**
     * The discount a ticket_discount code gives this order.
     *
     * @return array{promo: PromoCode, discount: float}
     *
     * @throws InvalidArgumentException with a reason the customer can read
     */
    public function quote(WpUser $user, ?string $code, float $orderTotal): array
    {
        $promo = $this->find($code);

        if (! $promo || $promo->kind !== 'ticket_discount') {
            throw new InvalidArgumentException('That promo code isn\'t valid or has ended.');
        }

        if ($orderTotal < (float) $promo->min_order) {
            throw new InvalidArgumentException('This code needs an order of at least ₦'.number_format((float) $promo->min_order).'.');
        }

        if ($promo->new_customers_only && RaffleEntry::query()->where('user_id', $user->ID)->exists()) {
            throw new InvalidArgumentException('This code is for first orders only.');
        }

        $this->assertUsesLeft($promo, $user->ID);

        $discount = round($orderTotal * (float) $promo->percent_off / 100, 2);

        if ($promo->max_discount) {
            $discount = min($discount, (float) $promo->max_discount);
        }

        // Always leave something to pay.
        $discount = min($discount, round($orderTotal - 1, 2));

        return ['promo' => $promo, 'discount' => max(0.0, $discount)];
    }

    /**
     * Uses the code up, inside the purchase's own database transaction:
     * the code row is locked and its limits re-checked, so two orders at
     * once can't both take the last use.
     *
     * @throws InvalidArgumentException when it ran out a moment ago
     */
    public function redeemInPurchase(PromoCode $promo, WpUser $user, float $discount, int $raffleTransactionId): void
    {
        $locked = PromoCode::query()->whereKey($promo->id)->lockForUpdate()->first();
        $this->assertUsesLeft($locked, $user->ID);

        PromoRedemption::create([
            'promo_code_id' => $promo->id,
            'user_id' => $user->ID,
            'context' => 'checkout',
            'value' => $discount,
            'raffle_transaction_id' => $raffleTransactionId,
        ]);

        WpUserMeta::query()->where('user_id', $user->ID)->where('meta_key', self::SAVED_CODE_META)->delete();
    }

    /** A discount code this customer was given at sign-up and hasn't used yet. */
    public function savedCodeFor(int $userId): ?string
    {
        $code = WpUserMeta::query()->where('user_id', $userId)->where('meta_key', self::SAVED_CODE_META)->value('meta_value');

        return $code && $this->find($code) ? $code : null;
    }

    /**
     * What each code achieved (Growth → Promo codes).
     *
     * @return array{uses: int, customers: int, buyers: int, spend: float, given: float}
     */
    public function stats(PromoCode $promo): array
    {
        $customers = CustomerSource::query()->where('promo_code_id', $promo->id)->pluck('user_id');

        return [
            'uses' => $promo->redemptions()->count(),
            'customers' => $customers->count(),
            'buyers' => $customers->isEmpty() ? 0 : RaffleEntry::query()->whereIn('user_id', $customers)->distinct()->count('user_id'),
            'spend' => $customers->isEmpty() ? 0.0 : (float) DB::table('wallet_ledger_entries')
                ->whereIn('user_id', $customers)->where('reason', 'ticket_purchase')->where('direction', 'debit')->sum('amount'),
            'given' => (float) $promo->redemptions()->sum('value'),
        ];
    }

    /** @throws InvalidArgumentException */
    private function assertUsesLeft(?PromoCode $promo, ?int $userId = null): void
    {
        if (! $promo || ! $promo->is_active) {
            throw new InvalidArgumentException('That promo code isn\'t valid or has ended.');
        }

        if ($promo->max_uses !== null && PromoRedemption::query()->where('promo_code_id', $promo->id)->count() >= $promo->max_uses) {
            throw new InvalidArgumentException('This promo code has been fully used.');
        }

        if ($userId !== null && PromoRedemption::query()->where('promo_code_id', $promo->id)->where('user_id', $userId)->count() >= max(1, $promo->max_uses_per_user)) {
            throw new InvalidArgumentException('You have already used this promo code.');
        }
    }
}
