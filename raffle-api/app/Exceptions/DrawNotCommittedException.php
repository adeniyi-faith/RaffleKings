<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * A draw's random seed must be committed (and its hash published) BEFORE
 * the draw runs — that ordering is the entire point of a provably-fair
 * scheme. Running a draw with no prior commitment would mean the
 * "commitment" could be chosen after the fact, which proves nothing.
 */
class DrawNotCommittedException extends RuntimeException
{
    public function __construct(int $raffleId)
    {
        parent::__construct("Raffle #{$raffleId} has no committed draw seed yet — call commitSeed() first.");
    }
}
