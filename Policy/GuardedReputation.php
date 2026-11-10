<?php

namespace Omnishield\Policy;

use Omnisense\Contracts\Policy\PolicyGuard;
use Omnishield\Model\Identity;
use Omnishield\Model\Reputation;
use Omnishield\ReputationInterface;

/** A list of known abusers behind its guard: each lookup() is the operation "lookup". */
final class GuardedReputation extends Guarded implements ReputationInterface
{
    public function __construct(private readonly ReputationInterface $gateway, PolicyGuard $guard)
    {
        parent::__construct($gateway, $guard);
    }

    public function lookup(Identity $identity): Reputation
    {
        return $this->through('lookup', fn () => $this->gateway->lookup($identity), array_filter(['ip' => $identity->ip]));
    }

    public function inner(): ReputationInterface
    {
        return $this->gateway;
    }
}
