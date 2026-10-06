<?php

namespace App\Services;

use App\Contracts\PaymentGateway;
use App\Exceptions\PaymentGatewayException;
use App\Models\Deposit;
use App\Models\Legacy\WpUser;
use App\Notifications\DepositConfirmed;
use App\Notifications\ReferralCommissionEarned;
use App\Services\Payments\FlutterwaveGateway;
use App\Services\Payments\PaystackGateway;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use InvalidArgumentException;
use RuntimeException;

/**
 * The single settlement path for turning a payment gateway's confirmed
 * payment into an actual wallet credit — same discipline as
 * TicketPurchaseService and WithdrawalService: one place, one DB
 * transaction, the user's wallet row locked for the duration, paired
 * with a WalletLedgerEntry.
 *
 * Paystack is the default deposit gateway (config/payments.php); if
 * initializing a deposit with it throws, this automatically retries
 * with Flutterwave rather than failing the deposit outright. Once a
 * deposit has actually been created against a gateway, that gateway is
 * the only one ever consulted again for it — there is no failover
 * during confirmation, only at initialization.
 *
 * The legacy site's Gemini AI screenshot check is NOT replaced here —
 * it stays available as the manual-review path for deposits neither
 * gateway can handle (see config/payments.php's docblock).
 */
class DepositService
{
    /** @var array<string, PaymentGateway> */
    private array $gateways;

    public function __construct(
        private readonly WalletLedgerService $ledger,
        private readonly ReferralCommissionService $referrals,
        PaystackGateway $paystack,
        FlutterwaveGateway $flutterwave,
    ) {
        $this->gateways = [
            $paystack->name() => $paystack,
            $flutterwave->name() => $flutterwave,
        ];
    }

    public function initialize(WpUser $user, float $amount, string $callbackUrl, ?string $returnTo = null): Deposit
    {
        if ($amount < config('payments.minimum_deposit')) {
            throw new InvalidArgumentException(sprintf('Minimum deposit is ₦%.2f.', config('payments.minimum_deposit')));
        }

        $reference = 'dep_'.Str::uuid();

        $deposit = Deposit::create([
            'user_id' => $user->ID,
            'reference' => $reference,
            'amount' => $amount,
            'currency' => 'NGN',
            'status' => 'pending',
            'return_to' => $returnTo,
        ]);

        $errors = [];

        foreach ($this->gatewayOrder() as $gatewayName) {
            $gateway = $this->gateways[$gatewayName] ?? null;

            if (! $gateway) {
                continue;
            }

            try {
                $result = $gateway->initialize($user, $amount, $reference, $callbackUrl);

                $deposit->update([
                    'gateway' => $gatewayName,
                    'authorization_url' => $result->authorizationUrl,
                ]);

                return $deposit;
            } catch (PaymentGatewayException $e) {
                $errors[] = "{$gatewayName}: {$e->getMessage()}";

                Log::warning('Deposit gateway failed during initialization, trying next.', [
                    'reference' => $reference,
                    'gateway' => $gatewayName,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        $deposit->update(['status' => 'failed', 'failure_reason' => implode(' | ', $errors)]);

        app(\App\Services\Analytics\Analytics::class)->capture($user->ID, 'topup_failed', ['amount' => $amount, 'reason' => 'no_gateway_available']);

        throw new PaymentGatewayException('All payment gateways are currently unavailable: '.implode(' | ', $errors));
    }

    /**
     * Re-verifies directly with the named gateway (never trusts a
     * webhook payload's own claimed status/amount) and settles the
     * deposit if confirmed. Idempotent — a duplicate webhook or a
     * repeated status poll for an already-settled deposit is a no-op,
     * not a double credit.
     *
     * @throws PaymentGatewayException
     */
    public function confirm(string $gatewayName, string $reference): Deposit
    {
        $gateway = $this->gateways[$gatewayName]
            ?? throw new InvalidArgumentException("Unknown payment gateway: {$gatewayName}");

        $verification = $gateway->verify($reference);
        $referralCommission = null;
        $outcome = null; // what changed just now (not an already-settled repeat)

        $deposit = DB::transaction(function () use ($reference, $gatewayName, $verification, &$referralCommission, &$outcome) {
            $deposit = Deposit::query()->where('reference', $reference)->lockForUpdate()->first();

            if (! $deposit) {
                throw new RuntimeException("No deposit found for reference {$reference}.");
            }

            if (in_array($deposit->status, ['successful', 'amount_mismatch'], true)) {
                return $deposit; // already settled or flagged — idempotent no-op
            }

            // Only the gateway the top-up was started with can settle it, so
            // a payment made elsewhere under the same reference never counts.
            if ($deposit->gateway && $deposit->gateway !== $gatewayName) {
                throw new RuntimeException("Deposit {$reference} was started with {$deposit->gateway}, not {$gatewayName}.");
            }

            if (! $verification->successful && ! $verification->isFinalFailure()) {
                // Still processing at the gateway (pending, ongoing, queued…):
                // the customer may well have paid. Not a failure; look again
                // later (recheckPending runs every few minutes).
                $deposit->update([
                    'last_checked_at' => now(),
                    'check_count' => ((int) $deposit->check_count) + 1,
                ]);

                return $deposit;
            }

            if (! $verification->successful) {
                $deposit->update([
                    'last_checked_at' => now(),
                    'check_count' => ((int) $deposit->check_count) + 1,
                    'status' => 'failed',
                    'gateway_transaction_id' => $verification->gatewayTransactionId,
                    'failure_reason' => "Gateway reported status: {$verification->rawStatus}",
                ]);
                $outcome = 'topup_payment_failed';

                return $deposit;
            }

            // Compared with what we asked for, so a fee Paystack added on top
            // for the customer (its "customer pays the fees" setting) is not
            // mistaken for a wrong amount. Only what we asked for is credited:
            // the fee went to Paystack, not to us.
            // The amount only means something in the right currency: a
            // payment of "5,000" in another currency is not ₦5,000.
            // Flutterwave's checkout lets a payer pick the currency, so this
            // has to be checked, not assumed.
            $wrongCurrency = strtoupper($verification->currency) !== strtoupper((string) ($deposit->currency ?: 'NGN'));

            if ($wrongCurrency || round($verification->creditableAmount(), 2) !== round((float) $deposit->amount, 2)) {
                // Paid, but not the expected amount — never credit blindly;
                // flag for manual review instead of guessing which figure
                // to trust.
                $deposit->update([
                    'status' => 'amount_mismatch',
                    'gateway_transaction_id' => $verification->gatewayTransactionId,
                    'failure_reason' => sprintf('Expected %.2f %s, gateway confirmed %.2f %s.', $deposit->amount, strtoupper((string) ($deposit->currency ?: 'NGN')), $verification->amount, strtoupper($verification->currency)),
                ]);
                $outcome = 'topup_amount_mismatch';

                return $deposit;
            }

            $deposit->update([
                'gateway' => $gatewayName,
                'status' => 'successful',
                'gateway_transaction_id' => $verification->gatewayTransactionId,
                'verified_at' => now(),
            ]);

            $this->ledger->credit(
                userId: $deposit->user_id,
                balanceType: 'wallet',
                amount: (string) $deposit->amount,
                reason: 'deposit',
                key: "deposit:{$deposit->id}",
                from: 'gateway_clearing',
                referenceType: Deposit::class,
                referenceId: $deposit->id,
                description: "Deposit via {$gatewayName}",
            );

            $referralCommission = $this->referrals->payCommissionForFirstDeposit($deposit->user, (float) $deposit->amount, $deposit->id);
            $outcome = 'topup_completed';

            return $deposit;
        });

        if ($outcome !== null) {
            app(\App\Services\Analytics\Analytics::class)->capture($deposit->user_id, $outcome, [
                'amount' => (float) $deposit->amount,
                'gateway' => $gatewayName,
            ]);
        }

        // Fired AFTER the transaction commits — never inside it, so a
        // notification can't go out for a deposit that then rolls back.
        // Same "notify after commit" discipline as TicketPurchaseService/
        // ProvablyFairDrawService.
        if ($deposit->status === 'successful') {
            $deposit->user->notify(new DepositConfirmed($deposit));

            // Affiliates: commission for whoever brought this customer (held a few days).
            if ($outcome === 'topup_completed') {
                app(\App\Services\Growth\AffiliateService::class)->recordDeposit($deposit);
            }

            // A commission held for a multi-account check isn't announced yet.
            if ($referralCommission && $referralCommission->status !== 'held') {
                $referrer = WpUser::find($referralCommission->referrer_user_id);
                $referrer?->notify(new ReferralCommissionEarned($referralCommission));

                app(\App\Services\Analytics\Analytics::class)->capture($referralCommission->referrer_user_id, 'referral_commission_earned', [
                    'amount' => (float) $referralCommission->commission_amount,
                ]);
            }
        }

        return $deposit;
    }

    /** Top-ups still waiting are looked at again for this many days. */
    public const RECHECK_DAYS = 2;

    /**
     * Every few minutes (routes/console.php): asks the gateway again about
     * top-ups that were started but never confirmed, so a missed webhook or
     * a customer who closed the tab never leaves paid money unclaimed.
     * Anything still unconfirmed after RECHECK_DAYS is reported to staff.
     *
     * @return int how many were looked at
     */
    public function recheckPending(): int
    {
        $looked = 0;

        Deposit::query()
            ->whereIn('status', ['pending', 'failed'])
            ->whereNotNull('gateway')
            ->where('created_at', '>=', now()->subDays(self::RECHECK_DAYS))
            ->where('created_at', '<=', now()->subMinutes(5))
            ->where(fn ($q) => $q->whereNull('last_checked_at')->orWhere('last_checked_at', '<=', now()->subMinutes(9)))
            ->orderBy('id')
            ->limit(100)
            ->get()
            ->each(function (Deposit $deposit) use (&$looked) {
                $looked++;

                try {
                    $this->confirm($deposit->gateway, $deposit->reference);
                } catch (\Throwable $e) {
                    $deposit->forceFill(['last_checked_at' => now(), 'check_count' => ((int) $deposit->check_count) + 1])->save();
                    report($e);
                }
            });

        $old = Deposit::query()->where('status', 'pending')->whereNotNull('gateway')
            ->where('created_at', '<', now()->subDays(self::RECHECK_DAYS))
            ->where('created_at', '>=', now()->subDays(self::RECHECK_DAYS + 5))
            ->count();

        if ($old > 0) {
            \App\Services\Monitoring\StaffAlerts::send("{$old} top-up(s) have been waiting more than ".self::RECHECK_DAYS.' days without the gateway confirming them. Check Money → Top-ups.', 'old-pending-deposits', 1440);
        }

        return $looked;
    }

    public function gatewayFor(string $name): PaymentGateway
    {
        return $this->gateways[$name] ?? throw new InvalidArgumentException("Unknown payment gateway: {$name}");
    }

    /**
     * @return string[]
     */
    private function gatewayOrder(): array
    {
        return array_values(array_unique(array_filter([
            config('payments.default_gateway'),
            config('payments.backup_gateway'),
        ])));
    }
}
