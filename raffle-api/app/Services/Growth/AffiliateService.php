<?php

namespace App\Services\Growth;

use App\Models\Deposit;
use App\Models\Growth\Affiliate;
use App\Models\Growth\AffiliateEarning;
use App\Models\Growth\CustomerSource;
use App\Models\Legacy\RaffleEntry;
use App\Models\Legacy\WpUser;
use App\Models\Wallet;
use App\Services\Risk\AbuseDetector;
use App\Services\WalletLedgerService;
use App\Support\Features;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Influencer and affiliate links (Settings → On / off → New features;
 * Growth → Affiliates). Separate from ordinary customer referrals:
 *
 *  - An affiliate has a link (/go/their-code) and can own promo codes.
 *    A visitor who clicks it and signs up within 30 days, or signs up with
 *    one of their codes, is theirs (customer_sources, first one wins).
 *  - They earn commission_percent of every top-up those customers make
 *    during their first commission_days.
 *  - Each earning is held for hold_days (time to spot fraud or a charge-
 *    back), then paid into the affiliate's winnings by releaseDue(),
 *    which they can withdraw like any winnings. Staff can cancel a held
 *    earning; with multi-account protection on, earnings from a customer
 *    who looks like the affiliate themselves are put on hold for review.
 */
class AffiliateService
{
    public const COOKIE = 'rk_aff';

    public const COOKIE_DAYS = 30;

    public function __construct(private readonly WalletLedgerService $ledger) {}

    public function findByCode(?string $code): ?Affiliate
    {
        $code = strtolower(trim((string) $code));

        if ($code === '' || ! Features::on('affiliates')) {
            return null;
        }

        return Affiliate::query()->where('code', $code)->where('is_active', true)->first();
    }

    /** One more click on an affiliate's link today. */
    public function recordClick(Affiliate $affiliate): void
    {
        try {
            $updated = DB::table('affiliate_clicks')->where('affiliate_id', $affiliate->id)->where('day', today()->toDateString())->increment('clicks');

            if (! $updated) {
                DB::table('affiliate_clicks')->insert(['affiliate_id' => $affiliate->id, 'day' => today()->toDateString(), 'clicks' => 1]);
            }
        } catch (UniqueConstraintViolationException) {
            DB::table('affiliate_clicks')->where('affiliate_id', $affiliate->id)->where('day', today()->toDateString())->increment('clicks');
        }
    }

    /** A new customer arrived through an affiliate's link. Never breaks the sign-up. */
    public function attributeSignup(WpUser $user, ?string $cookieCode): void
    {
        try {
            $affiliate = $this->findByCode($cookieCode);

            if (! $affiliate || $affiliate->user_id === $user->ID) {
                return;
            }

            CustomerSource::query()->firstOrCreate(['user_id' => $user->ID], ['affiliate_id' => $affiliate->id]);
        } catch (Throwable $e) {
            report($e);
        }
    }

    /**
     * A top-up just succeeded (DepositService::confirm, after it commits):
     * the affiliate who brought this customer earns their share, held for
     * now. Never breaks the top-up.
     */
    public function recordDeposit(Deposit $deposit): ?AffiliateEarning
    {
        if (! Features::on('affiliates')) {
            return null;
        }

        try {
            $affiliateId = CustomerSource::query()->where('user_id', $deposit->user_id)->value('affiliate_id');
            $affiliate = $affiliateId ? Affiliate::query()->find($affiliateId) : null;

            if (! $affiliate || ! $affiliate->is_active || $affiliate->user_id === (int) $deposit->user_id) {
                return null;
            }

            $customer = WpUser::query()->find($deposit->user_id);
            $joined = $customer?->user_registered ? Carbon::parse($customer->user_registered) : null;

            if (! $joined || $joined->lt(now()->subDays($affiliate->commission_days))) {
                return null; // outside the commission window
            }

            $commission = round((float) $deposit->amount * $affiliate->commission_percent / 100, 2);

            if ($commission <= 0) {
                return null;
            }

            // Multi-account protection: an affiliate "bringing" themselves.
            $suspicion = Features::on('abuse_detection') && $customer
                ? app(AbuseDetector::class)->linkBetween($affiliate->user_id, $customer->ID)
                : null;

            return AffiliateEarning::create([
                'affiliate_id' => $affiliate->id,
                'customer_id' => $deposit->user_id,
                'source_type' => 'deposit',
                'source_id' => $deposit->id,
                'base_amount' => (float) $deposit->amount,
                'commission' => $commission,
                'status' => $suspicion ? 'on_hold' : 'held',
                'note' => $suspicion ? mb_substr('Held for review: '.$suspicion, 0, 200) : null,
                'available_at' => now()->addDays($affiliate->hold_days),
            ]);
        } catch (UniqueConstraintViolationException) {
            return null; // this top-up was already counted
        } catch (Throwable $e) {
            report($e);

            return null;
        }
    }

    /**
     * Daily: earnings past their hold go into the affiliate's winnings.
     *
     * @return int how many were paid
     */
    public function releaseDue(): int
    {
        if (! Features::on('affiliates')) {
            return 0;
        }

        $paid = 0;

        $due = AffiliateEarning::query()->where('status', 'held')->where('available_at', '<=', now())->limit(500)->pluck('id');

        foreach ($due as $id) {
            $paid += (int) $this->pay($id);
        }

        return $paid;
    }

    /** Staff approved an earning that was on hold (Growth → Affiliates). */
    public function approve(AffiliateEarning $earning): bool
    {
        if ($earning->status !== 'on_hold') {
            return false;
        }

        $earning->update(['status' => 'held', 'note' => 'Checked by staff.']);

        return $earning->available_at?->isPast() ? $this->pay($earning->id) : true;
    }

    public function cancel(AffiliateEarning $earning, string $reason): bool
    {
        return (bool) AffiliateEarning::query()->whereKey($earning->id)->whereIn('status', ['held', 'on_hold'])
            ->update(['status' => 'cancelled', 'note' => mb_substr($reason, 0, 200), 'updated_at' => now()]);
    }

    private function pay(int $earningId): bool
    {
        return DB::transaction(function () use ($earningId) {
            $earning = AffiliateEarning::query()->with('affiliate')->whereKey($earningId)->lockForUpdate()->first();

            if (! $earning || $earning->status !== 'held' || ! $earning->affiliate) {
                return false;
            }

            $userId = $earning->affiliate->user_id;
            $wallet = Wallet::query()->where('user_id', $userId)->lockForUpdate()->first()
                ?? Wallet::create(['user_id' => $userId, 'wallet_balance' => 0, 'earnings_balance' => 0]);

            $wallet->earnings_balance = (float) $wallet->earnings_balance + $earning->commission;
            $wallet->save();

            $this->ledger->recordCredit($userId, 'earnings', $earning->commission, 'affiliate_commission', 'affiliate_earning', $earning->id, 'Affiliate commission');

            $earning->update(['status' => 'paid', 'paid_at' => now()]);

            return true;
        });
    }

    /**
     * The affiliate's dashboard numbers.
     *
     * @return array<string, mixed>
     */
    public function dashboard(Affiliate $affiliate): array
    {
        $customers = CustomerSource::query()->where('affiliate_id', $affiliate->id)->pluck('user_id');
        $earnings = AffiliateEarning::query()->where('affiliate_id', $affiliate->id);

        return [
            'name' => $affiliate->name,
            'code' => $affiliate->code,
            'link' => $affiliate->link(),
            'commission_percent' => $affiliate->commission_percent,
            'commission_days' => $affiliate->commission_days,
            'hold_days' => $affiliate->hold_days,
            'is_active' => $affiliate->is_active,
            'clicks_30_days' => (int) DB::table('affiliate_clicks')->where('affiliate_id', $affiliate->id)->where('day', '>=', today()->subDays(29)->toDateString())->sum('clicks'),
            'clicks_total' => (int) DB::table('affiliate_clicks')->where('affiliate_id', $affiliate->id)->sum('clicks'),
            'signups' => $customers->count(),
            'players' => $customers->isEmpty() ? 0 : RaffleEntry::query()->whereIn('user_id', $customers)->distinct()->count('user_id'),
            'topped_up' => (clone $earnings)->distinct()->count('customer_id'),
            'earned' => [
                'waiting' => round((float) (clone $earnings)->whereIn('status', ['held', 'on_hold'])->sum('commission'), 2),
                'paid' => round((float) (clone $earnings)->where('status', 'paid')->sum('commission'), 2),
            ],
            'codes' => $affiliate->promoCodes()->get()->map(fn ($p) => [
                'code' => $p->code,
                'summary' => $p->summary(),
                'is_live' => $p->is_active && (! $p->ends_at || $p->ends_at->isFuture()),
                'signups' => CustomerSource::query()->where('promo_code_id', $p->id)->count(),
            ])->values(),
            'recent' => (clone $earnings)->with('customer')->latest('id')->limit(20)->get()->map(fn (AffiliateEarning $e) => [
                'date' => $e->created_at?->toIso8601String(),
                'customer' => self::maskName($e->customer?->display_name ?: $e->customer?->user_login),
                'top_up' => $e->base_amount,
                'commission' => $e->commission,
                'status' => $e->status === 'on_hold' ? 'held' : $e->status,
                'available_at' => $e->available_at?->toIso8601String(),
            ])->values(),
        ];
    }

    /** "Chinedu" → "Ch****u": affiliates see activity, not who their customers are. */
    public static function maskName(?string $name): string
    {
        $name = (string) $name;

        return mb_strlen($name) <= 2 ? '***' : mb_substr($name, 0, 2).str_repeat('*', max(3, mb_strlen($name) - 3)).mb_substr($name, -1);
    }
}
