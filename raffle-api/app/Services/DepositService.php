<?php

namespace App\Services;

use App\Contracts\PaymentGateway;
use App\Exceptions\PaymentGatewayException;
use App\Models\Deposit;
use App\Models\Legacy\WpUser;
use App\Models\Wallet;
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

    public function initialize(WpUser $user, float $amount, string $callbackUrl): Deposit
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

        return DB::transaction(function () use ($reference, $gatewayName, $verification) {
            $deposit = Deposit::query()->where('reference', $reference)->lockForUpdate()->first();

            if (! $deposit) {
                throw new RuntimeException("No deposit found for reference {$reference}.");
            }

            if (in_array($deposit->status, ['successful', 'amount_mismatch'], true)) {
                return $deposit; // already settled or flagged — idempotent no-op
            }

            if (! $verification->successful) {
                $deposit->update([
                    'status' => 'failed',
                    'gateway_transaction_id' => $verification->gatewayTransactionId,
                    'failure_reason' => "Gateway reported status: {$verification->rawStatus}",
                ]);

                return $deposit;
            }

            if (round($verification->amount, 2) !== round((float) $deposit->amount, 2)) {
                // Paid, but not the expected amount — never credit blindly;
                // flag for manual review instead of guessing which figure
                // to trust.
                $deposit->update([
                    'status' => 'amount_mismatch',
                    'gateway_transaction_id' => $verification->gatewayTransactionId,
                    'failure_reason' => sprintf('Expected %.2f, gateway confirmed %.2f.', $deposit->amount, $verification->amount),
                ]);

                return $deposit;
            }

            $deposit->update([
                'gateway' => $gatewayName,
                'status' => 'successful',
                'gateway_transaction_id' => $verification->gatewayTransactionId,
                'verified_at' => now(),
            ]);

            $wallet = Wallet::query()->where('user_id', $deposit->user_id)->lockForUpdate()->first()
                ?? Wallet::create(['user_id' => $deposit->user_id, 'wallet_balance' => 0, 'earnings_balance' => 0]);

            $wallet->wallet_balance = (float) $wallet->wallet_balance + (float) $deposit->amount;
            $wallet->save();

            $this->ledger->recordCredit(
                userId: $deposit->user_id,
                balanceType: 'wallet',
                amount: (float) $deposit->amount,
                reason: 'deposit',
                referenceType: Deposit::class,
                referenceId: $deposit->id,
                description: "Deposit via {$gatewayName}",
            );

            $this->referrals->payCommissionForFirstDeposit($deposit->user, (float) $deposit->amount, $deposit->id);

            return $deposit;
        });
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
