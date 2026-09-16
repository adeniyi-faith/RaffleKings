<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * Thrown when a withdrawal needs the account-verification fee authorized
 * first. A client that already checked WithdrawalService::requirements()
 * beforehand (as it should — see the audit's UX findings on this) should
 * never actually hit this; it exists as a safety net, not the primary
 * way this requirement is communicated.
 */
class VerificationFeeRequiredException extends RuntimeException
{
    public function __construct(public readonly float $feeAmount)
    {
        parent::__construct("Account verification required — authorize a ₦{$feeAmount} verification fee to continue.");
    }
}
