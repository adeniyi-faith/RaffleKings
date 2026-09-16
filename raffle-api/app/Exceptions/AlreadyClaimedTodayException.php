<?php

namespace App\Exceptions;

use RuntimeException;

class AlreadyClaimedTodayException extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('Already claimed today.');
    }
}
