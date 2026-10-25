<?php

namespace Omnishield\Testing;

use Omnishield\ChallengeInterface;
use Omnishield\ChallengeIssuerInterface;
use Omnishield\ClassifierInterface;
use Omnishield\Exception\InvalidConfigException;
use Omnishield\Exception\InvalidKeyException;
use Omnishield\Exception\NotSupportedException;
use Omnishield\Exception\ProviderException;
use Omnishield\Exception\UnreachableException;
use Omnishield\GatewayFactory;
use Omnishield\GatewayFactoryInterface;
use Omnishield\GatewayInterface;
use Omnishield\LocalizableInterface;
use Omnishield\Model\Attempt;
use Omnishield\Model\Classification;
use Omnishield\Model\Identity;
use Omnishield\Model\Label;
use Omnishield\Model\Reputation;
use Omnishield\Model\Submission;
use Omnishield\Model\Verdict;
use Omnishield\Model\Widget;
use Omnishield\Registry;
use Omnishield\ReputationInterface;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * What every gateway does, as PHPUnit tests: a gateway package's test case
 * uses this trait, gives its factory, the options that build a working
 * gateway and a few answers its provider recorded - and the suite checks the
 * family's contract, question by question, for the questions the gateway's
 * Capabilities claim:
 *
 * - the factory's name is the gateway's; the Registry builds it from
 *   {factory, options}; a required option left out is an
 *   InvalidConfigException;
 * - what the Capabilities claim is what the gateway is: each question it
 *   answers is its interface, and the Registry refuses the others with a
 *   NotSupportedException; a classifier that takes no report says so the same
 *   way; a gateway that sends nothing to a third party makes no HTTP request;
 * - its answers keep their invariants: a Verdict passes when it has no
 *   reason, its reasons are the family's, its score lies between 0 and 1 and
 *   only where the gateway scores; a Classification's label is a Label; a
 *   Reputation's confidence lies between 0 and 100, a known one says which
 *   parts matched, among the parts the list reads;
 * - nothing is asked for nothing: an empty token is a Verdict missing, an
 *   empty identity is unknown, without a request;
 * - the provider's errors are the family's exceptions (third-party gateways):
 *   no answer, HTTP 5xx and 429 are an UnreachableException; HTTP 401 and 403
 *   an InvalidKeyException when the gateway sends a key or a secret, a
 *   ProviderException otherwise; an answer it cannot read a ProviderException -
 *   never a TypeError, a JsonException or a silent refusal;
 * - the recorded cases are answered as recorded, every recorded answer read.
 *
 * No test reaches the network: the factory is handed a MockHttpClient.
 *
 *   final class TurnstileContractTest extends TestCase
 *   {
 *       use GatewayContractTests;
 *
 *       protected function factory(HttpClientInterface $http): GatewayFactoryInterface { return new TurnstileGatewayFactory($http); }
 *       protected function options(): array { return ['site_key' => '1x00000000000000000000AA', 'secret' => '1x0000000000000000000000000000000AA']; }
 *       protected function recordedCases(): iterable
 *       {
 *           yield 'passes' => [new Attempt('XXXX.DUMMY.TOKEN.XXXX'), true, [new MockResponse(file_get_contents(__DIR__.'/Fixtures/siteverify-testing-passes.json'))]];
 *           yield 'spent' => [new Attempt('XXXX.DUMMY.TOKEN.XXXX'), false, [new MockResponse(file_get_contents(__DIR__.'/Fixtures/siteverify-testing-spent.json'))]];
 *       }
 *   }
 *
 * Requires phpunit/phpunit and symfony/http-client (the package's require-dev).
 */
trait GatewayContractTests
{
    /** The factory under test; a gateway that calls a provider sends its requests to $http. */
    abstract protected function factory(HttpClientInterface $http): GatewayFactoryInterface;

    /** @return array<string, mixed> options that build a working gateway */
    abstract protected function options(): array;

    /**
     * Questions put to the gateway, and the answer it must give - from what its
     * provider answered when it was asked (recorded), for a third-party gateway.
     * Each case: [input, accepted, recorded answers = [], option overrides = []]
     * - input: an Attempt (a captcha), a Submission (a classifier), an Identity
     *   (a list), or a closure that makes one from the gateway (an ALTCHA token
     *   solved from its issue());
     * - accepted: the Verdict passes, the Classification is ham, the Reputation
     *   is not known;
     * - recorded answers: the provider's, in the order the gateway asks; each is
     *   read, no more are asked for.
     * At least one accepted and one refused case for each question the gateway answers.
     *
     * @return iterable<string, array{0: Attempt|Submission|Identity|\Closure(GatewayInterface): (Attempt|Submission|Identity), 1: bool, 2?: list<MockResponse>, 3?: array<string, mixed>}>
     */
    abstract protected function recordedCases(): iterable;

    /**
     * The options the factory cannot do without. By default what a
     * GatewayFactory says it requires ("omnishield.required_options").
     *
     * @return list<string>
     */
    protected function requiredOptions(): array
    {
        $factory = $this->factory(self::silentHttp());

        return $factory instanceof GatewayFactory ? array_values((array) $factory->createConfig($this->options())->get('omnishield.required_options', [])) : [];
    }

    /** Whether the gateway sends a key or a secret of the site's with its requests (an HTTP 401 or 403 is then an InvalidKeyException). */
    protected function sendsCredentials(): bool
    {
        return [] !== $this->requiredOptions();
    }

    /** A Submission the gateway can send (a classifier). */
    protected function sampleSubmission(): Submission
    {
        return new Submission('Thank you, this helped.', 'Camille', 'camille@example.org', ip: '192.0.2.10', userAgent: 'Mozilla/5.0', site: 'https://example.org');
    }

    /** An Identity the gateway asks its provider about (a list). */
    protected function sampleIdentity(): Identity
    {
        return new Identity('192.0.2.10', 'camille@example.org', 'camille');
    }

    public function testTheFactoryNamesTheGatewayAndTheRegistryBuildsIt(): void
    {
        $factory = $this->factory(self::silentHttp());
        $gateway = $factory->create($this->options());

        self::assertSame($factory->getName(), $gateway->getName(), 'the gateway is named after its factory');
        self::assertNotSame('', $gateway->getTitle());

        $registry = new Registry([$factory], ['contract' => ['factory' => $factory->getName(), 'options' => $this->options()]]);
        self::assertSame($gateway->getName(), $registry->get('contract')->getName());
        self::assertSame($registry->get('contract'), $registry->get('contract'), 'built once');
        self::assertSame([$factory->getName()], $registry->factories());
    }

    public function testARequiredOptionLeftOutIsAConfigurationError(): void
    {
        $required = $this->requiredOptions();
        foreach ($required as $option) {
            try {
                $this->factory(self::silentHttp())->create(array_diff_key($this->options(), [$option => true]));
                self::fail(\sprintf('A gateway was built without its "%s" option.', $option));
            } catch (InvalidConfigException $e) {
                self::assertStringContainsString($option, $e->getMessage());
            }
            try {
                $this->factory(self::silentHttp())->create([$option => ''] + $this->options());
                self::fail(\sprintf('A gateway was built with an empty "%s" option.', $option));
            } catch (InvalidConfigException) {
            }
        }
        if ([] === $required) {
            self::assertInstanceOf(GatewayInterface::class, $this->factory(self::silentHttp())->create(), 'no option required: built from none');
        }
    }

    public function testItIsWhatItsCapabilitiesSay(): void
    {
        $gateway = $this->gateway();
        $capabilities = $gateway->capabilities();

        self::assertNotSame([], $capabilities->questions(), 'a gateway answers at least one question');
        self::assertSame($capabilities->challenge, $gateway instanceof ChallengeInterface, 'challenge: ChallengeInterface');
        self::assertSame($capabilities->classifier, $gateway instanceof ClassifierInterface, 'classifier: ClassifierInterface');
        self::assertSame($capabilities->reputation, $gateway instanceof ReputationInterface, 'reputation: ReputationInterface');
        self::assertSame([], array_diff($capabilities->reads, [Reputation::IP, Reputation::EMAIL, Reputation::NAME]), 'reads: ip, email, name');
        self::assertSame($capabilities->reputation, [] !== $capabilities->reads, 'a list says what it reads, and only a list');
        if ($capabilities->actions || $capabilities->scores) {
            self::assertTrue($capabilities->challenge, 'actions and scores are a captcha\'s');
        }
        self::assertIsArray(json_decode((string) json_encode($capabilities->toArray()), true));

        $registry = new Registry([$this->factory(self::silentHttp())], ['contract' => ['factory' => $gateway->getName(), 'options' => $this->options()]]);
        foreach (['challenge' => $capabilities->challenge, 'classifier' => $capabilities->classifier, 'reputation' => $capabilities->reputation] as $question => $answers) {
            try {
                $registry->{$question}('contract');
                self::assertTrue($answers, \sprintf('the registry gave it as a %s, which it does not claim to be', $question));
            } catch (NotSupportedException $e) {
                self::assertFalse($answers, \sprintf('the registry refused it as a %s: %s', $question, $e->getMessage()));
            }
        }
    }

    public function testAGatewayThatCallsNobodyMakesNoRequest(): void
    {
        $gateway = $this->gateway();
        if ($gateway->capabilities()->thirdParty) {
            self::assertTrue(true, 'a third-party gateway: its requests are checked on recorded answers');

            return;
        }
        $requests = 0;
        $gateway = $this->factory(new MockHttpClient(static function () use (&$requests): MockResponse {
            ++$requests;

            return new MockResponse('{}');
        }))->create($this->options());
        foreach ($this->questions($gateway) as $ask) {
            try {
                $ask($gateway);
            } catch (\Omnishield\Exception\OmnishieldException) {
            }
        }
        self::assertSame(0, $requests, 'it says nothing leaves the site: no HTTP request');
    }

    public function testAChallengeShowsAWidgetAndRefusesAMissingTokenWithoutAsking(): void
    {
        $gateway = $this->gateway();
        if (!$gateway instanceof ChallengeInterface) {
            self::assertFalse($gateway->capabilities()->challenge);

            return;
        }
        $capabilities = $gateway->capabilities();
        $widget = $gateway->widget('contact');
        self::assertInstanceOf(Widget::class, $widget);
        self::assertNotSame('', $widget->field, 'the field the token is posted in');
        self::assertSame($capabilities->thirdParty, $widget->thirdParty, 'the widget says what the Capabilities say: third party');
        self::assertSame($capabilities->cookies, $widget->cookies, 'the widget says what the Capabilities say: cookies');
        self::assertStringContainsString('<'.$widget->tag, $widget->html('n0nce'));
        if ($capabilities->actions) {
            self::assertSame('contact', $widget->action, 'a captcha that checks actions makes its widget for the one asked');
        }
        foreach ($widget->origins as $origin) {
            self::assertMatchesRegularExpression('~^https?://[^/]+$~', $origin, 'an origin, scheme and host');
        }

        $verdict = $this->withoutRequests()->verify(new Attempt('', '192.0.2.10'));
        self::assertFalse($verdict->passed);
        self::assertSame([Verdict::MISSING], $verdict->reasons);

        if ($gateway instanceof ChallengeIssuerInterface) {
            $challenge = $gateway->issue('contact');
            self::assertNotSame([], $challenge);
            self::assertNotSame($challenge, $gateway->issue('contact'), 'a fresh challenge each time');
            self::assertIsString(json_encode($challenge, \JSON_THROW_ON_ERROR));
        }
        if ($gateway instanceof LocalizableInterface) {
            $localized = $gateway->localized('fr', ['label' => 'Contract']);
            self::assertInstanceOf($gateway::class, $localized);
            self::assertSame($gateway->getName(), $localized->getName());
            self::assertSame([], array_filter($gateway->texts(), static fn ($text) => !\is_string($text)), 'texts: strings');
        }
    }

    public function testAClassifierThatTakesNoReportSaysSo(): void
    {
        $gateway = $this->gateway();
        if (!$gateway instanceof ClassifierInterface || $gateway->capabilities()->reports) {
            self::assertTrue(true, 'not a classifier, or one that takes reports');

            return;
        }
        $this->expectException(NotSupportedException::class);
        $this->withoutRequests()->report($this->sampleSubmission(), true);
    }

    public function testAnEmptyIdentityIsUnknownWithoutAsking(): void
    {
        $gateway = $this->gateway();
        if (!$gateway instanceof ReputationInterface) {
            self::assertFalse($gateway->capabilities()->reputation);

            return;
        }
        $reputation = $this->withoutRequests()->lookup(new Identity());
        self::assertFalse($reputation->known);
        self::assertSame([], $reputation->reasons);
    }

    public function testTheRecordedCasesAreAnsweredAsRecorded(): void
    {
        $seen = [];
        foreach ($this->recordedCases() as $name => $case) {
            [$input, $accepted] = $case;
            $answers = $case[2] ?? [];
            $requests = 0;
            $http = new MockHttpClient(static function () use (&$answers, &$requests, $name): MockResponse {
                ++$requests;

                return array_shift($answers) ?? throw new \LogicException(\sprintf('Case "%s": the gateway asked more than was recorded.', $name));
            });
            $gateway = $this->factory($http)->create(($case[3] ?? []) + $this->options());
            if ($input instanceof \Closure) {
                $input = $input($gateway);
            }

            [$question, $answer] = match (true) {
                $input instanceof Attempt => ['challenge', self::challenge($gateway, $name)->verify($input)],
                $input instanceof Submission => ['classifier', self::classifier($gateway, $name)->classify($input)],
                $input instanceof Identity => ['reputation', self::reputation($gateway, $name)->lookup($input)],
                default => throw new \LogicException(\sprintf('Case "%s": an Attempt, a Submission or an Identity.', $name)),
            };
            $this->assertKeepsItsInvariants($gateway, $answer, $name);
            $was = match (true) {
                $answer instanceof Verdict => $answer->passed,
                $answer instanceof Classification => !$answer->isSpam(),
                $answer instanceof Reputation => !$answer->known,
            };
            self::assertSame($accepted, $was, \sprintf('Case "%s": %s.', $name, $accepted ? 'accepted as recorded' : 'refused as recorded'));
            self::assertSame([], $answers, \sprintf('Case "%s": every recorded answer is read.', $name));
            if (!$gateway->capabilities()->thirdParty) {
                self::assertSame(0, $requests, \sprintf('Case "%s": no request from a gateway that calls nobody.', $name));
            }
            $seen[$question][$accepted ? 'accepted' : 'refused'] = true;
        }

        foreach ($this->gateway()->capabilities()->questions() as $question) {
            self::assertArrayHasKey('accepted', $seen[$question] ?? [], \sprintf('a recorded case the %s accepts', $question));
            self::assertArrayHasKey('refused', $seen[$question] ?? [], \sprintf('a recorded case the %s refuses', $question));
        }
    }

    public function testTheProvidersErrorsAreTheFamilysExceptions(): void
    {
        if (!$this->gateway()->capabilities()->thirdParty) {
            self::assertTrue(true, 'a gateway that calls nobody has no provider to fail');

            return;
        }
        $failures = [
            'no answer' => [static fn () => new MockResponse('', ['error' => 'Connection refused']), UnreachableException::class],
            'HTTP 500' => [static fn () => new MockResponse('<html>Internal Server Error</html>', ['http_code' => 500]), UnreachableException::class],
            'HTTP 503' => [static fn () => new MockResponse('{"error": "unavailable"}', ['http_code' => 503, 'response_headers' => ['content-type' => 'application/json']]), UnreachableException::class],
            'HTTP 429' => [static fn () => new MockResponse('Too Many Requests', ['http_code' => 429, 'response_headers' => ['retry-after' => '30']]), UnreachableException::class],
            'HTTP 401' => [static fn () => new MockResponse('<html>Unauthorized</html>', ['http_code' => 401]), $this->sendsCredentials() ? InvalidKeyException::class : ProviderException::class],
            'HTTP 403' => [static fn () => new MockResponse('{"error": {"code": 403, "message": "Forbidden"}}', ['http_code' => 403, 'response_headers' => ['content-type' => 'application/json']]), $this->sendsCredentials() ? InvalidKeyException::class : ProviderException::class],
            'an answer it cannot read' => [static fn () => new MockResponse('<html><body>Hello</body></html>', ['response_headers' => ['content-type' => 'text/html']]), ProviderException::class],
        ];
        foreach ($failures as $failure => [$response, $expected]) {
            $gateway = $this->factory(new MockHttpClient(static fn () => $response()))->create($this->options());
            foreach ($this->questions($gateway) as $question => $ask) {
                $thrown = null;
                try {
                    $ask($gateway);
                } catch (\Throwable $e) {
                    $thrown = $e;
                }
                self::assertNotNull($thrown, \sprintf('%s, %s: no exception.', $question, $failure));
                self::assertInstanceOf($expected, $thrown, \sprintf('%s, %s: %s %s', $question, $failure, $thrown::class, $thrown->getMessage()));
                if ($thrown instanceof ProviderException) {
                    self::assertSame($gateway->getName(), $thrown->provider, 'the exception names the provider');
                }
            }
        }
    }

    private function assertKeepsItsInvariants(GatewayInterface $gateway, Verdict|Classification|Reputation $answer, string $case): void
    {
        $capabilities = $gateway->capabilities();
        if ($answer instanceof Verdict) {
            self::assertSame($answer->passed, [] === $answer->reasons, \sprintf('Case "%s": a Verdict passes when it has no reason.', $case));
            self::assertSame([], array_diff($answer->reasons, [Verdict::MISSING, Verdict::INVALID, Verdict::EXPIRED, Verdict::DUPLICATE, Verdict::SPENT, Verdict::ACTION, Verdict::HOSTNAME, Verdict::SCORE]), \sprintf('Case "%s": the family\'s reasons.', $case));
            self::assertSame([], array_filter($answer->codes, static fn ($code) => !\is_string($code)), 'codes: strings');
            if (null !== $answer->score) {
                self::assertTrue($capabilities->scores, \sprintf('Case "%s": a score from a gateway that says it does not score.', $case));
                self::assertGreaterThanOrEqual(0.0, $answer->score);
                self::assertLessThanOrEqual(1.0, $answer->score);
            }
            self::assertIsArray($answer->toArray());

            return;
        }
        if ($answer instanceof Classification) {
            self::assertInstanceOf(Label::class, $answer->label);
            self::assertSame([], array_filter($answer->reasons, static fn ($reason) => !\is_string($reason)), 'reasons: strings');

            return;
        }
        self::assertSame($answer->known, [] !== $answer->reasons, \sprintf('Case "%s": a known Reputation says which parts matched, an unknown one none.', $case));
        self::assertSame([], array_diff($answer->reasons, [...$capabilities->reads, Reputation::DISPOSABLE, Reputation::TOR]), \sprintf('Case "%s": what matched is a part the list reads.', $case));
        self::assertGreaterThanOrEqual(0, $answer->frequency);
        self::assertGreaterThanOrEqual(0.0, $answer->confidence);
        self::assertLessThanOrEqual(100.0, $answer->confidence);
        self::assertIsArray($answer->toArray());
    }

    /** @return array<string, \Closure(GatewayInterface): mixed> each question the gateway answers, asked once */
    private function questions(GatewayInterface $gateway): array
    {
        $questions = [];
        if ($gateway instanceof ChallengeInterface) {
            $questions['verify()'] = static fn (ChallengeInterface $g) => $g->verify(new Attempt('a-token-of-the-visitor', '192.0.2.10'));
        }
        if ($gateway instanceof ClassifierInterface) {
            $submission = $this->sampleSubmission();
            $questions['classify()'] = static fn (ClassifierInterface $g) => $g->classify($submission);
        }
        if ($gateway instanceof ReputationInterface) {
            $identity = $this->sampleIdentity();
            $questions['lookup()'] = static fn (ReputationInterface $g) => $g->lookup($identity);
        }

        return $questions;
    }

    private function gateway(): GatewayInterface
    {
        return $this->factory(self::silentHttp())->create($this->options());
    }

    /** The gateway, on a client that fails the test if it is called. */
    private function withoutRequests(): GatewayInterface
    {
        return $this->factory(new MockHttpClient(static fn (string $method, string $url) => throw new \LogicException(\sprintf('No request expected: %s %s.', $method, $url))))->create($this->options());
    }

    private static function silentHttp(): HttpClientInterface
    {
        return new MockHttpClient(static fn (string $method, string $url) => throw new \LogicException(\sprintf('No request expected while the gateway is built: %s %s.', $method, $url)));
    }

    private static function challenge(GatewayInterface $gateway, string $case): ChallengeInterface
    {
        return $gateway instanceof ChallengeInterface ? $gateway : throw new \LogicException(\sprintf('Case "%s": an Attempt for a gateway that is no captcha.', $case));
    }

    private static function classifier(GatewayInterface $gateway, string $case): ClassifierInterface
    {
        return $gateway instanceof ClassifierInterface ? $gateway : throw new \LogicException(\sprintf('Case "%s": a Submission for a gateway that is no classifier.', $case));
    }

    private static function reputation(GatewayInterface $gateway, string $case): ReputationInterface
    {
        return $gateway instanceof ReputationInterface ? $gateway : throw new \LogicException(\sprintf('Case "%s": an Identity for a gateway that is no list.', $case));
    }
}
