<?php

namespace Omnishield\Exception;

use Omnisense\Contracts\Policy\UsageLimitReached;

/**
 * The provider did not answer: no connection, a timeout, a server error, a
 * quota exceeded. Nothing was decided about the token, the content or the
 * identity - whether to let the form through is the application's call.
 * A 429 and the calls held after it until its Retry-After is over carry a
 * UsageLimitReachedException as their previous: retryAfter().
 */
final class UnreachableException extends ProviderException
{
    /**
     * In how many seconds the provider said to try again (its 429's
     * Retry-After, or the wait a 429 earlier set), when it said so; null
     * otherwise.
     */
    public function retryAfter(): ?int
    {
        $previous = $this->getPrevious();

        return $previous instanceof UsageLimitReached ? $previous->retryAfter : null;
    }
}
