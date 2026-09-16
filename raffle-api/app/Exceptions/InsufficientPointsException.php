<?php

namespace App\Exceptions;

use RuntimeException;

class InsufficientPointsException extends RuntimeException
{
    public function __construct(public readonly int $shortfall)
    {
        parent::__construct(sprintf('Insufficient points. Short by %d.', $shortfall));
    }
}
