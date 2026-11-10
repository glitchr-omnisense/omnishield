<?php

namespace Omnishield\Policy;

use Omnisense\Contracts\Policy\PolicyGuard;
use Omnishield\LocalizableInterface;
use Omnishield\ChallengeIssuerInterface;

/** A captcha the site issues itself and whose widget takes its texts (ALTCHA), behind its guard: each verify() is the operation "verify"; issue() is not counted, localized() keeps the guard. */
final class GuardedLocalizableIssuer extends Guarded implements LocalizableInterface, ChallengeIssuerInterface
{
    use VerifiesThroughGuard, IssuesThroughGuard, LocalizesThroughGuard;

    public function __construct(private readonly LocalizableInterface&ChallengeIssuerInterface $gateway, PolicyGuard $guard)
    {
        parent::__construct($gateway, $guard);
    }

    public function inner(): LocalizableInterface&ChallengeIssuerInterface
    {
        return $this->gateway;
    }
}
