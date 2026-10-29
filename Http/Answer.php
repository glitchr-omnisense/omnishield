<?php

namespace Omnishield\Http;

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
    public static function send(HttpClientInterface $http, string $provider, string $method, string $url, array $options = []): self
    {
        try {
            $response = $http->request($method, $url, $options + ['timeout' => 10]);
            $status = $response->getStatusCode();
            $headers = $response->getHeaders(false);
            $body = $response->getContent(false);
        } catch (ExceptionInterface $e) {
            $secrets = Redaction::secrets($url, $options);

            throw Redaction::withoutArguments(new UnreachableException($provider, 'No answer: '.Redaction::text($e->getMessage(), $secrets), null, Redaction::copy($e, $secrets)));
        }
        if ($status >= 500 || 429 === $status) {
            throw Redaction::withoutArguments(new UnreachableException($provider, \sprintf('HTTP %d: %s', $status, mb_substr(trim($body), 0, 200)), (string) $status));
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

        return $data;
    }
}
