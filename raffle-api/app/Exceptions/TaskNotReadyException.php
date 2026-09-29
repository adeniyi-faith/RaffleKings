<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * A link task (join the community, follow on WhatsApp, share) was claimed
 * without opening its link first, or too soon after opening it — see
 * TaskClaimService. $secondsLeft is null when it was never opened.
 */
class TaskNotReadyException extends RuntimeException
{
    public function __construct(public readonly ?int $secondsLeft)
    {
        parent::__construct($secondsLeft === null
            ? 'Tap "Go" and complete the task first, then come back to claim your points.'
            : "Almost there! Come back in {$secondsLeft} second".($secondsLeft === 1 ? '' : 's').' to claim your points.');
    }
}
