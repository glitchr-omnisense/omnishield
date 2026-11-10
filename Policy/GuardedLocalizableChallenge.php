<?php

namespace Omnishield\Policy;

use Omnisense\Contracts\Policy\PolicyGuard;
use Omnishield\LocalizableInterface;

/** A captcha whose widget takes the site's texts, behind its guard: each verify() is the operation "verify"; localized() keeps the guard. */
final class GuardedLocalizableChallenge extends Guarded implements LocalizableInterface
{
    use VerifiesThroughGuard, LocalizesThroughGuard;

    public function __construct(private readonly LocalizableInterface $gateway, PolicyGuard $guard)
    {
        parent::__construct($gateway, $guard);
    }

    public function inner(): LocalizableInterface
    {
        return $this->gateway;
    }
}
