<?php

namespace App\Services\Payments;

final class PaymentInitializationResult
{
    public function __construct(
        public readonly string $authorizationUrl,
    ) {}
}
