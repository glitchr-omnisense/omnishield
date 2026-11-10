<?php

namespace Omnishield\Http;

use Omnisense\Contracts\Policy\Backoff;
use Omnisense\Contracts\Policy\UsageLimitReached;
use Omnishield\Exception\ProviderException;
use Omnishield\Exception\UnreachableException;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * A provider's answer, read whole: its status, headers (lower-case names)
 * and body. send() is the one place the family's gateways call through:
 * no connection, a timeout, a server error or a quota exceeded is an
 * UnreachableException - the provider said nothing about the request - and
 * anything else comes back for the gateway to read. The request's secrets
 * stay out of what it throws: the HTTP client's message with the key's
 * parameters masked (Redaction::text(), api_key=***), its exception wrapped
 * as a copy (Redaction::copy()), no trace keeping the arguments where the
 * options stood.
 *
 * Given the gateway's Backoff (Config::backoff()), the waits a provider asks
 * for are kept: a 429's Retry-After (or a RateLimit field with nothing left)
 * holds every next call of the gateway, refused without a request, until it
 * is over - each still an UnreachableException, as a 429 always was, whose
 * previous is the family's UsageLimitReachedException with the wait
 * (UnreachableException::retryAfter()).
 */
final readonly class Answer
{
    /** @param array<string, list<string>> $headers */
    public function __construct(
        public int $status,
        public array $headers,
        public string $body,
        private string $provider,
    ) {
    }

    /** @param array<string, mixed> $options the HTTP client's options: body, json, query, headers, timeout */
    public static function send(HttpClientInterface $http, string $provider, string $method, string $url, array $options = [], ?Backoff $backoff = null): self
    {
        try {
            $backoff?->clear();
        } catch (UsageLimitReached $e) {
            throw Redaction::withoutArguments(new UnreachableException($provider, $e->getMessage(), '429', $e));
        }
        try {
            $response = $http->request($method, $url, $options + ['timeout' => 10]);
            $status = $response->getStatusCode();
            $headers = $response->getHeaders(false);
            $body = $response->getContent(false);
        } catch (ExceptionInterface $e) {
            $secrets = Redaction::secrets($url, $options);

            throw Redaction::withoutArguments(new UnreachableException($provider, 'No answer: '.Redaction::text($e->getMessage(), $secrets), null, Redaction::copy($e, $secrets)));
        }
        $limited = null;
        try {
            $backoff?->observe($status, $headers);
        } catch (UsageLimitReached $e) {
            $limited = $e;
        }
        if ($status >= 500 || 429 === $status) {
            throw Redaction::withoutArguments(new UnreachableException($provider, \sprintf('HTTP %d: %s', $status, mb_substr(trim($body), 0, 200)), (string) $status, $limited));
        }

        return new self($status, $headers, $body, $provider);
    }

    public function header(string $name): ?string
    {
        return $this->headers[strtolower($name)][0] ?? null;
    }

    /**
     * The body as a JSON object.
     *
     * @return array<string, mixed>
     */
    public function json(): array
    {
        $data = json_decode($this->body, true);
        if (!\is_array($data)) {
            throw new ProviderException($this->provider, \sprintf('HTTP %d, not a JSON object: %s', $this->status, mb_substr(trim($this->body), 0, 200)));
        }
        $object = [];
        foreach ($data as $key => $value) {
            $object[(string) $key] = $value;
        }

        return $object;
    }
}
