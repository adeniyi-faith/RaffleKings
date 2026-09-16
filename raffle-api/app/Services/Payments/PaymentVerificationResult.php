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
    ) {}
}
