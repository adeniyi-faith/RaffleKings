<?php

namespace App\Exceptions;

use InvalidArgumentException;

/**
 * A purchase or game the customer's own responsible-play settings don't
 * allow (a spending limit, or a break). Extends InvalidArgumentException
 * so the purchase endpoints already show its message to the customer.
 */
class PlayLimitException extends InvalidArgumentException {}
