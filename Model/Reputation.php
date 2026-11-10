<?php

namespace Omnishield\Model;

/**
 * What a list knows of an identity: known for abuse or not, how often, how
 * surely, how recently - and which part of it matched.
 */
final readonly class Reputation
{
    public const IP = 'ip';
    public const EMAIL = 'email';
    public const NAME = 'name';
    /** The e-mail's domain is a disposable one. */
    public const DISPOSABLE = 'disposable';
    /** The address is a Tor exit node. */
    public const TOR = 'tor';

    /**
     * @param bool                    $known      whether the list holds it for abusive, at the gateway's threshold
     * @param int                     $frequency  how many times it was reported, where the list counts
     * @param float                   $confidence from 0 to 100: how surely it is abusive, as the list reckons
     * @param \DateTimeImmutable|null $lastSeen   when it was last reported
     * @param list<string>            $reasons    which parts matched: the constants above
     * @param array<string, mixed>    $data       what else the list answered, part by part
     */
    public function __construct(
        public bool $known,
        public int $frequency = 0,
        public float $confidence = 0.0,
        public ?\DateTimeImmutable $lastSeen = null,
        public array $reasons = [],
        public array $data = [],
    ) {
    }

    /** @param array<string, mixed> $data what the list answered */
    public static function unknown(array $data = []): self
    {
        return new self(false, data: $data);
    }

    public function matched(string $part): bool
    {
        return \in_array($part, $this->reasons, true);
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'lastSeen' => $this->lastSeen?->format(\DATE_ATOM),
            'known' => $this->known,
            'frequency' => $this->frequency,
            'confidence' => $this->confidence,
            'reasons' => $this->reasons,
            'data' => $this->data,
        ];
    }
}
