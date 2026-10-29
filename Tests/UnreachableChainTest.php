<?php

namespace Omnishield\Tests;

use Omnishield\Exception\UnreachableException;
use Omnishield\Http\Answer;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\Exception\TransportException;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * Nothing of the key anywhere in what a log of a failed call writes: the messages, the previous
 * exception's (a copy of the HTTP client's, its class and code kept, the key's parameter masked),
 * and the traces' arguments, which PHP keeps unless zend.exception_ignore_args is on.
 */
final class UnreachableChainTest extends TestCase
{
    private const SECRETS = ['secret-api-key-123', 'bearer-secret-456', 'body-secret-789'];

    private string|false $ignoreArgs = false;

    protected function setUp(): void
    {
        $this->ignoreArgs = ini_set('zend.exception_ignore_args', '0');
    }

    protected function tearDown(): void
    {
        ini_set('zend.exception_ignore_args', false !== $this->ignoreArgs ? $this->ignoreArgs : '1');
    }

    public function testNoPartOfAnUnreachableChainHoldsTheKey(): void
    {
        $http = new MockHttpClient(static function (string $method, string $url): never {
            throw new TransportException(\sprintf('Could not resolve host: api.example for "%s".', $url), 6);
        });

        try {
            self::send($http);
            self::fail('no exception');
        } catch (UnreachableException $e) {
            $previous = $e->getPrevious();
            self::assertInstanceOf(TransportException::class, $previous);
            self::assertSame(6, $previous->getCode());
            self::assertStringContainsString('api_key=***', $previous->getMessage());
            self::assertStringContainsString('api_key=***', $e->getMessage());
            self::assertClean($e);
        }
    }

    public function testAServerErrorKeepsNoKeyInItsTrace(): void
    {
        try {
            self::send(new MockHttpClient(new MockResponse('down', ['http_code' => 503])));
            self::fail('no exception');
        } catch (UnreachableException $e) {
            self::assertClean($e);
        }
    }

    private static function send(MockHttpClient $http): Answer
    {
        return Answer::send($http, 'example', 'POST', 'https://api.example/v1/check', ['query' => ['api_key' => 'secret-api-key-123', 'site' => 'a.example'], 'headers' => ['Authorization' => 'Bearer bearer-secret-456'], 'body' => ['secret' => 'body-secret-789']]);
    }

    private static function assertClean(\Throwable $e): void
    {
        self::assertNotEmpty($e->getTrace());
        for ($one = $e; null !== $one; $one = $one->getPrevious()) {
            foreach (self::SECRETS as $secret) {
                self::assertStringNotContainsString($secret, $one->getMessage());
                self::assertStringNotContainsString($secret, print_r($one->getTrace(), true));
                self::assertStringNotContainsString($secret, $one->getTraceAsString());
            }
        }
    }
}
