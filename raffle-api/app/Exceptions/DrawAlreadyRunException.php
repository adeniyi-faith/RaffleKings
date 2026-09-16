<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * Mirrors the legacy draw's own "Draw already completed for this raffle"
 * guard (rk_run_raffle_draw() in api-gamification.php) — checked against
 * the SAME wp_raffle_winners table the legacy system writes to, so this
 * guard holds regardless of which draw engine ran first.
 */
class DrawAlreadyRunException extends RuntimeException
{
    public function __construct(int $raffleId)
    {
        parent::__construct("Raffle #{$raffleId} has already been drawn.");
    }
}
