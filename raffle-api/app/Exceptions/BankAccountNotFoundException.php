<?php

namespace App\Exceptions;

use RuntimeException;

class BankAccountNotFoundException extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('Bank account not found.');
    }
}
