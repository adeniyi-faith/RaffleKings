<?php

namespace App\Exceptions;

use RuntimeException;

class MinimumWithdrawalNotMetException extends RuntimeException
{
    public function __construct(public readonly float $minimumAmount)
    {
        parent::__construct("Minimum withdrawal is ₦{$minimumAmount}.");
    }
}
