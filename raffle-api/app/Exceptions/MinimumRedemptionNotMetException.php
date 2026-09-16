<?php

namespace App\Exceptions;

use RuntimeException;

class MinimumRedemptionNotMetException extends RuntimeException
{
    public function __construct(public readonly int $minimumPoints, public readonly int $currentPoints)
    {
        parent::__construct("Minimum redemption is {$minimumPoints} points (have {$currentPoints}).");
    }
}
