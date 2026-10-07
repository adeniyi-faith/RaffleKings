<?php

namespace App\Exceptions;

use RuntimeException;

class BankAccountNotFoundException extends RuntimeException
{
    public function __construct(string $message = 'Bank account not found.')
    {
        parent::__construct($message);
    }
}
