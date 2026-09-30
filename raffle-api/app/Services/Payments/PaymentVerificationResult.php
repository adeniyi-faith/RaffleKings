<?php

namespace App\Services\Payments;

final class PaymentVerificationResult
{
    public function __construct(
        public readonly bool $successful,
        public readonly float $amount,
        public readonly string $currency,
        public readonly ?string $gatewayTransactionId,
        public readonly string $rawStatus,
        // What we asked the customer to pay, before any fee the gateway
        // added on top (Paystack "customer pays the fees"). Null = unknown.
        public readonly ?float $requestedAmount = null,
    ) {}

    /**
     * The money that is actually ours to credit: the amount asked for
     * when the gateway added its fee on top, otherwise what was paid.
     */
    public function creditableAmount(): float
    {
        return $this->requestedAmount !== null && $this->requestedAmount > 0 && $this->requestedAmount <= $this->amount
            ? $this->requestedAmount
            : $this->amount;
    }
}
