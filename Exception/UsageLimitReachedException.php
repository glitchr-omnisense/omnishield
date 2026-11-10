<?php

namespace Omnishield\Exception;

use Omnisense\Contracts\Policy\UsageLimitReached;

/**
 * A call refused before it was made: a rate limit of the gateway's usage
 * policy (as the site tightened it) is reached, or the provider asked to
 * wait. $retryAfter says in how many seconds to try again, when it is known.
 *
 * The guard of a typed accessor (Registry::challenge(), classifier(),
 * reputation()) throws it when a limit is reached. A provider's own 429, and
 * the calls it holds until its Retry-After is over, stay an
 * UnreachableException, as before: this exception is then its previous
 * (UnreachableException::retryAfter()).
 */
final class UsageLimitReachedException extends UsageLimitReached implements OmnishieldException
{
}
