<?php

namespace Omnishield\Policy;

use Omnisense\Contracts\Policy\PolicyGuard;
use Omnishield\ChallengeIssuerInterface;

/** A captcha the site issues itself (ALTCHA) behind its guard: each verify() is the operation "verify"; issue() is not counted. */
final class GuardedChallengeIssuer extends Guarded implements ChallengeIssuerInterface
{
    use VerifiesThroughGuard, IssuesThroughGuard;

    public function __construct(private readonly ChallengeIssuerInterface $gateway, PolicyGuard $guard)
    {
        parent::__construct($gateway, $guard);
    }

    public function inner(): ChallengeIssuerInterface
    {
        return $this->gateway;
    }
}
