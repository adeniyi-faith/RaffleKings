<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * Thrown when one or more requested ticket numbers were taken by someone
 * else between the user picking them and the purchase being settled — the
 * exact race condition the audit flagged (TD-06). Whoever loses this race
 * gets this exception INSTEAD of a debited balance and no ticket, because
 * TicketPurchaseService rolls the whole transaction back before this is
 * ever thrown.
 */
class TicketUnavailableException extends RuntimeException
{
    /** @param  int[]  $unavailableNumbers */
    public function __construct(public readonly array $unavailableNumbers)
    {
        parent::__construct(
            'Ticket number(s) no longer available: '.implode(', ', $unavailableNumbers)
        );
    }
}
