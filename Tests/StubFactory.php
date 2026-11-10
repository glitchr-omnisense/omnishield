<?php

namespace Omnishield\Tests;

use Omnishield\ChallengeIssuerInterface;
use Omnishield\Config;
use Omnishield\Exception\UnreachableException;
use Omnishield\GatewayFactory;
use Omnishield\GatewayInterface;
use Omnishield\Model\Attempt;
use Omnishield\Model\Capabilities;
use Omnishield\Model\Verdict;
use Omnishield\Model\Widget;
use Omnishield\Replay\InMemoryReplayStore;
use Omnishield\Replay\ReplayStoreInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * A captcha kept in memory, for the tests: it issues "good-<n>" tokens,
 * passes each once (through the replay store it is given), says "duplicate"
 * the second time, "invalid" to anything else, and does not answer to
 * "down". Its factory asks for what a real one does: a store, an HTTP
 * client.
 */
final class StubFactory extends GatewayFactory
{
    public function __construct(public readonly ?ReplayStoreInterface $replays = null, public readonly ?HttpClientInterface $http = null)
    {
    }

    protected function populate(Config $c): void
    {
        $c->defaults([
            'omnishield.factory_name' => 'stub',
            'omnishield.factory_title' => 'Stub',
            'omnishield.required_options' => ['secret'],
        ]);
    }

    protected function build(Config $c): GatewayInterface
    {
        return new StubGateway($this->replays ?? new InMemoryReplayStore(), $c->string('secret') ?? '', $this->http);
    }
}

final class StubGateway implements ChallengeIssuerInterface
{
    private int $issued = 0;

    public function __construct(public readonly ReplayStoreInterface $replays, public readonly string $secret, public readonly ?HttpClientInterface $http)
    {
    }

    public function getName(): string
    {
        return 'stub';
    }

    public function getTitle(): string
    {
        return 'Stub';
    }

    public function capabilities(): Capabilities
    {
        return new Capabilities(challenge: true, thirdParty: false, actions: true);
    }

    public function widget(?string $action = null): Widget
    {
        return new Widget('stub-token', '/stub.js', ['type' => 'module'], 'stub-widget', ['data-action' => $action ?? false], action: $action);
    }

    public function issue(?string $action = null): array
    {
        return ['token' => 'good-'.++$this->issued, 'action' => $action];
    }

    public function verify(Attempt $attempt): Verdict
    {
        if ($attempt->isEmpty()) {
            return Verdict::fail(Verdict::MISSING);
        }
        if ('down' === $attempt->token) {
            throw new UnreachableException('stub', 'No answer.');
        }
        if (!str_starts_with($attempt->token, 'good-')) {
            return Verdict::fail(Verdict::INVALID);
        }
        if (!$this->replays->spend($attempt->token, new \DateTimeImmutable('+5 minutes'))) {
            return Verdict::fail(Verdict::DUPLICATE);
        }

        return new Verdict(true, action: $attempt->action, hostname: $attempt->hostname, at: new \DateTimeImmutable());
    }
}
