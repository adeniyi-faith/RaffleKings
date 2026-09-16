<?php

namespace App\Exceptions;

use RuntimeException;

class TaskAlreadyCompletedException extends RuntimeException
{
    public function __construct(string $taskId)
    {
        parent::__construct("Task \"{$taskId}\" was already completed.");
    }
}
