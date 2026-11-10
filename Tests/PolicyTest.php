<?php

namespace Omnishield\Tests;

use Omnisense\Contracts\Policy\InMemoryRateLimiter;
use Omnisense\Contracts\Policy\InvalidPolicyException;
use Omnisense\Contracts\Policy\UsageNotPermitted;
use Omnisense\Contracts\Policy\UsagePolicy;
use Omnishield\ChallengeIssuerInterface;
use Omnishield\Config;
use Omnishield\Exception\UnreachableException;
use Omnishield\Exception\UsageLimitReachedException;
use Omnishield\GatewayFactory;
use Omnishield\GatewayInterface;
use Omnishield\Http\Answer;
use Omnishield\Model\Attempt;
use Omnishield\Model\Capabilities;
use Omnishield\Model\Identity;
use Omnishield\Model\Reputation;
use Omnishield\Policy\Guarded;
use Omnishield\Policy\GuardedChallengeIssuer;
use Omnishield\Policy\GuardedReputation;
use Omnishield\Registry;
use Omnishield\Replay\InMemoryReplayStore;
use Omnishield\Replay\ReplayStoreInterface;
use Omnishield\ReputationInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Usage policies through the registry: a site's limit counted with the call
 * over it never made, a provider's 429 and its Retry-After holding the next
 * call, a tightening accepted and a looser one refused, a use the terms
 * forbid refused, and a gateway written before the policies still working.
 */
final class PolicyTest extends TestCase
{
    public function testASiteLimitIsCountedAndTheCallOverItNeverMade(): void
    {
        $replays = new CountingReplayStore();
        $registry = new Registry([new StubFactory($replays)], [
            'forms' => ['factory' => 'stub', 'options' => ['secret' => 's3cret'], 'policy' => ['rateLimits' => [['max' => 2, 'window' => 'minute', 'operations' => ['verify']]]]],
        ]);
        $challenge = $registry->challenge('forms');

        self::assertInstanceOf(GuardedChallengeIssuer::class, $challenge, 'a limited operation: the gateway behind its guard');
        self::assertInstanceOf(ChallengeIssuerInterface::class, $challenge, 'still the contract it keeps');
        self::assertSame($registry->get('forms'), $challenge instanceof Guarded ? $challenge->inner() : null);
        self::assertTrue($challenge->verify(new Attempt('good-1'))->passed);
        self::assertTrue($challenge->verify(new Attempt('good-2'))->passed);
        self::assertSame(0, $registry->guard('forms')->remaining('verify'));
        try {
            $challenge->verify(new Attempt('good-3'));
            self::fail('a third verification within the minute');
        } catch (UsageLimitReachedException $e) {
            self::assertGreaterThan(0, $e->retryAfter);
            self::assertSame(2, $e->limit?->max);
        }
        self::assertSame(2, $replays->spent, 'the call over the limit was never made');
        self::assertSame(['good-1', 'good-2'], $replays->tokens);
    }

    public function testAGatewayWithNoLimitIsGivenAsItIs(): void
    {
        $registry = new Registry([new StubFactory()], ['forms' => ['factory' => 'stub', 'options' => ['secret' => 's3cret']]]);

        self::assertSame($registry->get('forms'), $registry->challenge('forms'));
        self::assertSame('stub', $registry->policy('forms')->provider, 'named after its factory');
        self::assertNull($registry->policy('forms')->rateLimits, 'a gateway written before the policies: its limits unknown');
    }

    public function testA429AndItsRetryAfterHoldTheNextCall(): void
    {
        $requests = 0;
        $http = new MockHttpClient(static function () use (&$requests) {
            ++$requests;

            return 1 === $requests
                ? new MockResponse('Too Many Requests', ['http_code' => 429, 'response_headers' => ['retry-after' => '30']])
                : new MockResponse('{"known": false}', ['response_headers' => ['content-type' => 'application/json']]);
        });
        $registry = new Registry([new ProbeFactory($http)], ['list' => ['factory' => 'probe']]);
        $list = $registry->reputation('list');

        try {
            $list->lookup(new Identity('192.0.2.10'));
            self::fail('a 429');
        } catch (UnreachableException $e) {
            self::assertSame(30, $e->retryAfter(), 'a 429 is still unreachable, its wait read');
            self::assertInstanceOf(UsageLimitReachedException::class, $e->getPrevious());
        }
        try {
            $list->lookup(new Identity('192.0.2.11'));
            self::fail('held');
        } catch (UnreachableException $e) {
            self::assertGreaterThan(0, $e->retryAfter());
            self::assertLessThanOrEqual(30, $e->retryAfter());
        }
        self::assertSame(1, $requests, 'the held call sent nothing');
        self::assertGreaterThan(0, $registry->guard('list')->backoff()->left(), 'the wait is the registry guard\'s too');
    }

    public function testATighteningIsAcceptedAndALooserLimitRefused(): void
    {
        $tight = new Registry([new ProbeFactory()], ['list' => ['factory' => 'probe', 'policy' => ['rateLimits' => [['max' => 100, 'window' => 'day', 'operations' => ['lookup']]]]]]);
        $limits = $tight->policy('list')->limitsFor('lookup');

        self::assertSame([1000, 100], array_map(static fn ($l) => $l->max, $limits), 'the declared limit and the site\'s, both counted');
        self::assertInstanceOf(GuardedReputation::class, $tight->reputation('list'));

        $loose = new Registry([new ProbeFactory()], ['list' => ['factory' => 'probe', 'options' => ['policy' => ['rateLimits' => [['max' => 5000, 'window' => 'day', 'operations' => ['lookup']]]]]]]);
        $this->expectException(InvalidPolicyException::class);
        $loose->reputation('list');
    }

    public function testAUseTheTermsForbidIsRefusedAtOnce(): void
    {
        $registry = new Registry([new ProbeFactory()], [
            'personal' => ['factory' => 'probe', 'usage' => ['commercial' => false]],
            'shop' => ['factory' => 'probe', 'usage' => ['commercial' => true]],
        ]);

        self::assertInstanceOf(ReputationInterface::class, $registry->reputation('personal'));
        $this->expectException(UsageNotPermitted::class);
        $registry->reputation('shop');
    }

    public function testTheCapabilitiesCarryThePolicy(): void
    {
        $older = new Capabilities(challenge: true);
        self::assertSame(['challenge'], $older->contracts());
        self::assertSame(UsagePolicy::UNNAMED, $older->policy()->provider);
        self::assertSame(['questions', 'challenge', 'classifier', 'reputation', 'thirdParty', 'cookies', 'scores', 'actions', 'reports', 'reads', 'policy'], array_keys($older->toArray()));

        $declared = ProbeGateway::declared();
        $capabilities = new Capabilities(reputation: true, policy: $declared);
        self::assertSame($declared, $capabilities->policy());
        self::assertSame('forbidden', $capabilities->toArray()['policy']['rights']['commercial']);
    }
}

/** A replay store that counts what the gateway spends: a verification made. */
final class CountingReplayStore implements ReplayStoreInterface
{
    public int $spent = 0;

    /** @var list<string> */
    public array $tokens = [];

    private readonly InMemoryReplayStore $store;

    public function __construct()
    {
        $this->store = new InMemoryReplayStore();
    }

    public function spend(string $id, \DateTimeImmutable $until): bool
    {
        ++$this->spent;
        $this->tokens[] = $id;

        return $this->store->spend($id, $until);
    }
}

/** A list called over HTTP, declaring 1,000 lookups a day and no commercial use. */
final class ProbeFactory extends GatewayFactory
{
    public function __construct(private readonly ?HttpClientInterface $http = null)
    {
    }

    protected function populate(Config $c): void
    {
        $c->defaults(['omnishield.factory_name' => 'probe', 'omnishield.factory_title' => 'Probe', 'omnishield.required_options' => []]);
    }

    protected function build(Config $c): GatewayInterface
    {
        return new ProbeGateway($this->http ?? new MockHttpClient(), $c);
    }
}

final class ProbeGateway implements ReputationInterface
{
    public function __construct(private readonly HttpClientInterface $http, private readonly Config $config)
    {
    }

    public static function declared(): UsagePolicy
    {
        return UsagePolicy::fromArray([
            'provider' => 'probe',
            'rateLimits' => [['max' => 1000, 'window' => 'day', 'operations' => ['lookup']]],
            'rights' => ['commercial' => 'forbidden'],
        ]);
    }

    public function getName(): string
    {
        return 'probe';
    }

    public function getTitle(): string
    {
        return 'Probe';
    }

    public function capabilities(): Capabilities
    {
        return new Capabilities(reputation: true, reads: ['ip'], policy: self::declared());
    }

    public function lookup(Identity $identity): Reputation
    {
        $data = Answer::send($this->http, 'probe', 'GET', 'https://probe.example/lookup', ['query' => ['ip' => $identity->ip]], $this->config->backoff())->json();

        return true === ($data['known'] ?? false) ? new Reputation(true, confidence: 100.0) : Reputation::unknown();
    }
}
