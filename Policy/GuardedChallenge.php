<?php

namespace Omnishield\Policy;

use Omnisense\Contracts\Policy\PolicyGuard;
use Omnishield\ChallengeInterface;

/** A captcha behind its guard: each verify() is the operation "verify". */
final class GuardedChallenge extends Guarded implements ChallengeInterface
{
    use VerifiesThroughGuard;

    public function __construct(private readonly ChallengeInterface $gateway, PolicyGuard $guard)
    {
        parent::__construct($gateway, $guard);
    }

    public function inner(): ChallengeInterface
    {
        return $this->gateway;
    }
}
