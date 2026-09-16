<?php

namespace App\Exceptions;

use RuntimeException;

class NoEligibleEntriesException extends RuntimeException
{
    public function __construct(int $raffleId)
    {
        parent::__construct("Raffle #{$raffleId} has no eligible entries to draw from (no verified tickets, or every buyer is in the winner cooldown).");
    }
}
