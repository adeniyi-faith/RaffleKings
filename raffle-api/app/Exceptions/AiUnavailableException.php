<?php

namespace App\Exceptions;

use RuntimeException;

/** The AI couldn't be used right now; the message is safe to show staff. */
class AiUnavailableException extends RuntimeException {}
