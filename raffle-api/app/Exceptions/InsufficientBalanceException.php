<?php

namespace App\Exceptions;

use RuntimeException;

class InsufficientBalanceException extends RuntimeException
{
    public function __construct(public readonly float $shortfall)
    {
        parent::__construct(sprintf('Insufficient balance. Short by %.2f.', $shortfall));
    }
}
