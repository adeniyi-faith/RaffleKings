<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * Thrown by a PaymentGateway implementation when it can't complete a
 * call (the provider is down, rejected the request, network error…).
 * DepositService catches this during initialize() specifically to
 * trigger automatic failover to the backup gateway.
 */
class PaymentGatewayException extends RuntimeException
{
    /**
     * True when we can't tell whether the provider acted on the request: no
     * answer, "too busy" or a server error. A payout in that state must be
     * checked, never treated as failed and never sent again blindly.
     */
    public function __construct(string $message = '', int $code = 0, ?\Throwable $previous = null, public readonly bool $unclear = false)
    {
        parent::__construct($message, $code, $previous);
    }

    public function isUnclear(): bool
    {
        return $this->unclear || $this->getPrevious() instanceof \Illuminate\Http\Client\ConnectionException;
    }
}
