<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * The money movement with this business key was already booked. The
 * database's unique key on ledger_journals.business_key is what raises it,
 * so a repeat can never be booked twice even if a status check is missed.
 */
class DuplicatePostingException extends RuntimeException
{
    public function __construct(public readonly string $businessKey)
    {
        parent::__construct("Already booked: {$businessKey}");
    }
}
