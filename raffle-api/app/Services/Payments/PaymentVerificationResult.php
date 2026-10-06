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
     * True only when the gateway says the payment definitely did not and will
     * not happen. "pending", "ongoing", "processing" and the like are NOT a
     * failure: the customer may have paid and the bank is still confirming.
     */
    public function isFinalFailure(): bool
    {
        return in_array(strtolower($this->rawStatus), ['failed', 'reversed', 'cancelled', 'canceled', 'rejected', 'error'], true);
    }

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
