<?php

namespace App\Exceptions;

use RuntimeException;

class NoPrizeStructureException extends RuntimeException
{
    public function __construct(int $raffleId)
    {
        parent::__construct("Raffle #{$raffleId} has no prize tiers yet. Add its prizes (Raffles → edit → Prize tiers) first.");
    }
}
