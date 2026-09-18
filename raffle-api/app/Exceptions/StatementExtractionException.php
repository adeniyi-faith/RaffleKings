<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * Thrown when a bank-statement image/PDF can't be turned into a list of
 * credit transactions — no Gemini key configured, the API call itself
 * failed, or the model's response couldn't be parsed as the expected
 * JSON array. Never silently returns an empty or fabricated list; the
 * caller (StatementExtractionController) surfaces this as a real error
 * so an admin re-uploads or falls back to reading the statement by eye,
 * rather than an audit run silently reconciling against zero credits.
 */
class StatementExtractionException extends RuntimeException
{
    //
}
