<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * Thrown when a ticket purchase targets a raffle that can't sell tickets
 * right now — unknown, still a draft, closed by an admin, past its end
 * date, or sold out (OVERHAUL_CHECKLIST.md item 43). The message is
 * written for the customer.
 */
class RaffleNotOnSaleException extends RuntimeException
{
    public function __construct(public readonly ?string $reason)
    {
        parent::__construct(match ($reason) {
            'ended' => 'This raffle has ended, so tickets are no longer on sale. No money has been taken.',
            'sold_out' => 'This raffle has sold out. No money has been taken.',
            'closed' => 'This raffle is closed, so tickets are no longer on sale. No money has been taken.',
            default => "This raffle isn't available. No money has been taken.",
        });
    }
}
