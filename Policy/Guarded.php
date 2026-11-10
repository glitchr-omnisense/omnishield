<?php

namespace Omnishield\Policy;

use Omnisense\Contracts\Policy\PolicyGuard;
use Omnisense\Contracts\Policy\UsageLimitReached;
use Omnishield\Exception\UnreachableException;
use Omnishield\GatewayInterface;
use Omnishield\Model\Capabilities;

/**
 * A gateway behind its guard: what every Guarded* shares - its name, title
 * and capabilities as they are, the guard, the gateway itself (inner(): what
 * a gateway offers beside its contract, Akismet's verifyKey(),
 * StopForumSpam's report()).
 *
 * The registry's typed accessors give one only when the gateway's policy, as
 * the site runs it, limits an operation of the contract; the gateway itself
 * otherwise.
 */
abstract class Guarded implements GatewayInterface
{
    public function __construct(private readonly GatewayInterface $wrapped, protected readonly PolicyGuard $guard)
    {
    }

    public function getName(): string
    {
        return $this->wrapped->getName();
    }

    public function getTitle(): string
    {
        return $this->wrapped->getTitle();
    }

    public function capabilities(): Capabilities
    {
        return $this->wrapped->capabilities();
    }

    /** The gateway, unguarded. */
    public function inner(): GatewayInterface
    {
        return $this->wrapped;
    }

    public function guard(): PolicyGuard
    {
        return $this->guard;
    }

    /**
     * The call through the guard. A limit reached is the family's
     * UsageLimitReachedException; a wait the provider asked for (its 429's
     * Retry-After) is an UnreachableException, as Http\Answer throws it for a
     * gateway given without a guard, the refusal as its previous.
     *
     * @template T
     *
     * @param \Closure(): T                      $call
     * @param array{key?: string, ip?: string, user?: string} $context
     *
     * @return T
     */
    protected function through(string $operation, \Closure $call, array $context = []): mixed
    {
        try {
            return $this->guard->call($operation, $call, $context);
        } catch (UsageLimitReached $e) {
            if (null !== $e->limit) {
                throw $e;
            }

            throw new UnreachableException($this->getName(), $e->getMessage(), '429', $e);
        }
    }
}
