<?php

namespace Omnishield;

use Omnisense\Contracts\Policy\InMemoryRateLimiter;
use Omnisense\Contracts\Policy\PolicyGuard;
use Omnisense\Contracts\Policy\RateLimiterInterface;
use Omnisense\Contracts\Provider\ProviderFactoryInterface;
use Omnisense\Contracts\Provider\ProviderInterface;
use Omnishield\Exception\InvalidConfigException;
use Omnishield\Exception\NotSupportedException;
use Omnishield\Policy\GuardedChallenge;
use Omnishield\Policy\GuardedChallengeIssuer;
use Omnishield\Policy\GuardedClassifier;
use Omnishield\Policy\GuardedLocalizableChallenge;
use Omnishield\Policy\GuardedLocalizableIssuer;
use Omnishield\Policy\GuardedReputation;

/**
 * The application's gateways by name, each built once from its factory and
 * options, each with its usage policy's guard (omnisense/contracts'
 * Registry):
 *
 *   new Registry([new AltchaGatewayFactory($replays), new AkismetGatewayFactory($http), new DisposableGatewayFactory()], [
 *       'forms' => ['factory' => 'altcha', 'options' => ['hmac_key' => '...']],
 *       'comments' => ['factory' => 'akismet', 'options' => ['api_key' => '...', 'site' => 'https://example.org'], 'usage' => ['commercial' => true, 'contract' => true]],
 *       'emails' => ['factory' => 'disposable'],
 *   ], $limiter);
 *
 *   $registry->challenge('forms')->verify($attempt);
 *
 * "policy" tightens a gateway's usage policy (never looser), "usage" says
 * what the site is, so that a use the provider's terms forbid fails at once
 * (UsageNotPermitted); both may sit in "options" too. challenge(),
 * classifier() and reputation() give the gateway behind its guard
 * (Policy\Guarded*) when its policy, as the site runs it, limits an
 * operation of the contract - a call over the limit is then never made
 * (UsageLimitReachedException) - and the gateway itself otherwise; get()
 * gives the gateway itself. Every gateway counts in the limiter given, an
 * in-memory one by default: an application served by many processes gives
 * a shared one.
 */
final class Registry extends \Omnisense\Contracts\Provider\Registry
{
    /** @var string */
    public const PREFIX = Config::PREFIX;

    private readonly RateLimiterInterface $shared;

    /**
     * @param iterable<GatewayFactoryInterface>                                                                                                $factories
     * @param array<string, array{factory: string, options?: array<string, mixed>, policy?: array<string, mixed>, usage?: array<string, mixed>}> $config
     */
    public function __construct(iterable $factories, array $config, ?RateLimiterInterface $limiter = null)
    {
        $this->shared = $limiter ?? new InMemoryRateLimiter();
        parent::__construct($factories, $config, $this->shared);
    }

    public function get(string $name): GatewayInterface
    {
        $gateway = parent::get($name);

        return $gateway instanceof GatewayInterface ? $gateway : throw new InvalidConfigException(\sprintf('The "%s" gateway is no omnishield gateway (%s).', $name, $gateway::class));
    }

    /** The gateway, as a captcha. */
    public function challenge(string $name): ChallengeInterface
    {
        $gateway = $this->get($name);
        if (!$gateway instanceof ChallengeInterface) {
            throw NotSupportedException::question($gateway, $name, 'is this token valid');
        }
        $guard = $this->guard($name);
        if (!self::limits($guard, 'verify')) {
            return $gateway;
        }

        return match (true) {
            $gateway instanceof LocalizableInterface && $gateway instanceof ChallengeIssuerInterface => new GuardedLocalizableIssuer($gateway, $guard),
            $gateway instanceof ChallengeIssuerInterface => new GuardedChallengeIssuer($gateway, $guard),
            $gateway instanceof LocalizableInterface => new GuardedLocalizableChallenge($gateway, $guard),
            default => new GuardedChallenge($gateway, $guard),
        };
    }

    /** The gateway, as a spam classifier. */
    public function classifier(string $name): ClassifierInterface
    {
        $gateway = $this->get($name);
        if (!$gateway instanceof ClassifierInterface) {
            throw NotSupportedException::question($gateway, $name, 'is this content spam');
        }
        $guard = $this->guard($name);

        return self::limits($guard, 'classify', 'report') ? new GuardedClassifier($gateway, $guard) : $gateway;
    }

    /** The gateway, as a list of known abusers. */
    public function reputation(string $name): ReputationInterface
    {
        $gateway = $this->get($name);
        if (!$gateway instanceof ReputationInterface) {
            throw NotSupportedException::question($gateway, $name, 'is this identity known for abuse');
        }
        $guard = $this->guard($name);

        return self::limits($guard, 'lookup') ? new GuardedReputation($gateway, $guard) : $gateway;
    }

    /**
     * A gateway built afresh, its configured options with $overrides over
     * them - a key typed in a back office, say. Not kept: get() still gives
     * the configured one. It counts in the registry's limiter.
     *
     * @param array<string, mixed> $overrides
     */
    public function create(string $name, array $overrides = []): GatewayInterface
    {
        $gateway = $this->factory($name)->create(array_replace($this->options($name), $overrides, [self::PREFIX.'.name' => $name, self::PREFIX.'.limiter' => $this->shared]));

        return $gateway instanceof GatewayInterface ? $gateway : throw new InvalidConfigException(\sprintf('The "%s" gateway is no omnishield gateway (%s).', $name, $gateway::class));
    }

    /** @return array<string, GatewayInterface> */
    public function all(): array
    {
        $all = [];
        foreach ($this->names() as $name) {
            $all[$name] = $this->get($name);
        }

        return $all;
    }

    protected function factory(string $name): ProviderFactoryInterface
    {
        $gateway = $this->config[$name] ?? throw new InvalidConfigException(\sprintf('No "%s" gateway; configured: %s.', $name, implode(', ', array_keys($this->config)) ?: 'none'));

        return $this->factories[$gateway['factory']] ?? throw new InvalidConfigException(\sprintf('No "%s" factory for the "%s" gateway; installed: %s.', $gateway['factory'], $name, implode(', ', array_keys($this->factories)) ?: 'none'));
    }

    protected function invalid(string $message): \Throwable
    {
        return new InvalidConfigException($message);
    }

    protected function notSupported(ProviderInterface $provider, string $name, string $capability): \Throwable
    {
        return new NotSupportedException(\sprintf('The "%s" gateway does not %s.', $name, $capability));
    }

    /** Whether the policy, as the site runs it, limits one of these operations. */
    private static function limits(PolicyGuard $guard, string ...$operations): bool
    {
        foreach ($operations as $operation) {
            if ([] !== $guard->policy()->limitsFor($operation)) {
                return true;
            }
        }

        return false;
    }
}
