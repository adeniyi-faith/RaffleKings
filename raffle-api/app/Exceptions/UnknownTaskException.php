<?php

namespace App\Exceptions;

use InvalidArgumentException;

class UnknownTaskException extends InvalidArgumentException
{
    public function __construct(string $taskId)
    {
        parent::__construct("Unknown task: \"{$taskId}\".");
    }
}
