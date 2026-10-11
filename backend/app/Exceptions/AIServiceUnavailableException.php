<?php

namespace App\Exceptions;

/**
 * Thrown when every configured AI model is overloaded, rate-limited or unreachable,
 * so callers can show a "busy, try again" message instead of a generic failure.
 * $quotaExhausted is set when every model has used up its daily quota, so trying
 * again later today won't help.
 */
class AIServiceUnavailableException extends \Exception
{
    public function __construct(string $message = '', public readonly bool $quotaExhausted = false)
    {
        parent::__construct($message);
    }
}
