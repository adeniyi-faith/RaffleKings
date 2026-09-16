<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * Thrown by a PaymentGateway implementation when it can't complete a
 * call (the provider is down, rejected the request, network error…).
 * DepositService catches this during initialize() specifically to
 * trigger automatic failover to the backup gateway.
 */
class PaymentGatewayException extends RuntimeException {}
