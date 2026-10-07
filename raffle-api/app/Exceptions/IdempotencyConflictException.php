<?php

namespace App\Exceptions;

use RuntimeException;

/** The same request key was reused for a different request. */
class IdempotencyConflictException extends RuntimeException {}
