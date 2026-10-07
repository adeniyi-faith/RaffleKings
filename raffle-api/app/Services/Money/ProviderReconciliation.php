<?php

namespace App\Services\Money;

use App\Exceptions\PaymentGatewayException;
use App\Models\Deposit;
use App\Models\MoneyReviewItem;
use App\Models\PayoutAttempt;
use App\Services\Monitoring\StaffAlerts;
use App\Services\Payments\FlutterwaveGateway;
use App\Services\Payments\PaystackApi;
use Illuminate\Support\Carbon;

/**
 * The nightly comparison of OUR records with what the payment providers say
 * (money-safety audit G3): every difference becomes an item on the "Needs
 * checking" list.
 *
 *  - A payment the provider took that we never credited (a lost webhook).
 *  - A payment we credited that the provider has no record of.
 *  - A different amount on the two sides.
 *  - A payout the provider sent that we don't show as paid, or one we show as
 *    paid that the provider never sent.
 *
 * The window ends an hour ago so a payment still travelling isn't flagged.
 */
class ProviderReconciliation
{
    public function __construct(
        private readonly PaystackApi $paystack,
        private readonly FlutterwaveGateway $flutterwave,
    ) {}

    /**
     * @return array{checked: int, differences: int, skipped: list<string>}
     */
    public function run(int $days = 2): array
    {
        $to = now()->subHour();
        $from = now()->subDays($days)->startOfDay();
        $out = ['checked' => 0, 'differences' => 0, 'skipped' => []];

        foreach (['paystack' => fn () => $this->paystack->successfulTransactions($from, $to), 'flutterwave' => fn () => $this->flutterwave->successfulTransactions($from, $to)] as $gateway => $list) {
            if (! $this->configured($gateway)) {
                $out['skipped'][] = $gateway;

                continue;
            }

            try {
                $this->comparePayments($gateway, $list(), $from, $to, $out);
            } catch (PaymentGatewayException $e) {
                $out['skipped'][] = "{$gateway} ({$e->getMessage()})";
            }
        }

        if ($this->configured('paystack')) {
            try {
                $this->comparePayouts($this->paystack->transfersBetween($from, $to), $from, $to, $out);
            } catch (PaymentGatewayException $e) {
                $out['skipped'][] = "paystack transfers ({$e->getMessage()})";
            }
        }

        if ($out['differences'] > 0) {
            StaffAlerts::send("The nightly check against Paystack/Flutterwave found {$out['differences']} difference(s). See Needs checking.", 'provider-reconcile-'.now()->toDateString(), 1440);
        }

        return $out;
    }

    private function configured(string $gateway): bool
    {
        return $gateway === 'paystack' ? $this->paystack->configured() : filled(config('services.flutterwave.secret_key'));
    }

    /** @param  list<array{reference: string, amount: float, currency: string}>  $theirs */
    private function comparePayments(string $gateway, array $theirs, Carbon $from, Carbon $to, array &$out): void
    {
        $byReference = [];

        foreach ($theirs as $row) {
            // Only our own top-ups (the account may be used for other things too).
            if (! str_starts_with($row['reference'], 'dep_')) {
                continue;
            }

            $byReference[$row['reference']] = $row;
            $out['checked']++;
            $deposit = Deposit::query()->where('reference', $row['reference'])->first();

            if (! $deposit || ! in_array($deposit->status, ['successful', 'amount_mismatch'], true)) {
                // amount_mismatch already has its own queue; anything else is money we never credited.
                $out['differences']++;
                MoneyReviewItem::raise('provider_missing_here', "{$gateway}:{$row['reference']}", ucfirst($gateway).' took ₦'.number_format($row['amount'], 2).' that we never credited', "Reference {$row['reference']}. Our record says: ".($deposit?->status ?? 'no such top-up').'. Re-check it from Money → Payments.');

                continue;
            }

            if ($deposit->status === 'successful' && round((float) $deposit->amount, 2) !== round($row['amount'], 2) && round($row['amount'], 2) < round((float) $deposit->amount, 2)) {
                // A bigger amount on their side is just their fee on top; only a SMALLER one is a problem.
                $out['differences']++;
                MoneyReviewItem::raise('provider_amount', "{$gateway}:{$row['reference']}", ucfirst($gateway).' took less than we credited', "We credited ₦{$deposit->amount}; {$gateway} says ₦".number_format($row['amount'], 2).". Reference {$row['reference']}.");
            }
        }

        Deposit::query()
            ->where('gateway', $gateway)->where('status', 'successful')
            ->where('verified_at', '>=', $from)->where('verified_at', '<=', $to)
            ->each(function (Deposit $deposit) use ($byReference, $gateway, &$out) {
                if (! isset($byReference[$deposit->reference])) {
                    $out['differences']++;
                    MoneyReviewItem::raise('we_have_no_provider_record', "{$gateway}:{$deposit->reference}", "We credited ₦{$deposit->amount} but {$gateway} has no such payment", "Reference {$deposit->reference}, customer #{$deposit->user_id}. Check the {$gateway} dashboard.");
                }
            });
    }

    /** @param  list<array{reference: string, amount: float, status: string}>  $theirs */
    private function comparePayouts(array $theirs, Carbon $from, Carbon $to, array &$out): void
    {
        $successful = [];

        foreach ($theirs as $row) {
            if (! str_starts_with($row['reference'], 'rkwd-')) {
                continue; // not sent by this app
            }

            $out['checked']++;

            if ($row['status'] !== 'success') {
                continue;
            }

            $successful[$row['reference']] = $row;
            $attempt = PayoutAttempt::query()->where('reference', $row['reference'])->first();

            if (! $attempt || $attempt->status !== 'success') {
                $out['differences']++;
                MoneyReviewItem::raise('payout_missing_here', $row['reference'], 'Paystack sent ₦'.number_format($row['amount'], 2).' that we do not show as paid', "Reference {$row['reference']}. Our record of this attempt says: ".($attempt?->status ?? 'unknown').'.');
            }
        }

        PayoutAttempt::query()->where('status', 'success')->where('resolved_at', '>=', $from)->where('resolved_at', '<=', $to)
            ->each(function (PayoutAttempt $attempt) use ($successful, &$out) {
                if (! isset($successful[$attempt->reference])) {
                    $out['differences']++;
                    MoneyReviewItem::raise('payout_not_at_provider', $attempt->reference, 'We show a payout as sent that Paystack has no successful record of', "Reference {$attempt->reference}, withdrawal #{$attempt->withdrawal_request_id}.");
                }
            });
    }
}
