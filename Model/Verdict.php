<?php

namespace Omnishield\Model;

/**
 * What a captcha said of a token: passed or not, and why - in the family's
 * words (reasons), and in the provider's own (codes).
 */
final readonly class Verdict
{
    /** No token was posted. */
    public const MISSING = 'missing';
    /** The token is not one the provider made for this site: malformed, forged, for another key. */
    public const INVALID = 'invalid';
    /** The token lapsed. */
    public const EXPIRED = 'expired';
    /** The token was used already. */
    public const DUPLICATE = 'duplicate';
    /** The token lapsed or was used already: the provider does not say which. */
    public const SPENT = 'spent';
    /** It was made for another action than the one expected. */
    public const ACTION = 'action';
    /** It was made on another host than the one expected. */
    public const HOSTNAME = 'hostname';
    /** Its score is under the threshold. */
    public const SCORE = 'score';

    /**
     * @param float|null              $score    from 0.0 (a bot) to 1.0 (a person), where the provider scores
     * @param string|null             $action   the action the token was made for, as the provider read it
     * @param string|null             $hostname the host the widget was shown on, as the provider read it
     * @param \DateTimeImmutable|null $at       when the challenge was solved
     * @param list<string>            $reasons  why it did not pass: the constants above
     * @param list<string>            $codes    the provider's own error codes, or its own reasons
     */
    public function __construct(
        public bool $passed,
        public ?float $score = null,
        public ?string $action = null,
        public ?string $hostname = null,
        public ?\DateTimeImmutable $at = null,
        public array $reasons = [],
        public array $codes = [],
    ) {
    }

    /** @param list<string> $codes */
    public static function fail(string $reason, array $codes = []): self
    {
        return new self(false, reasons: [$reason], codes: $codes);
    }

    public function failedFor(string $reason): bool
    {
        return \in_array($reason, $this->reasons, true);
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'at' => $this->at?->format(\DATE_ATOM),
            'passed' => $this->passed,
            'score' => $this->score,
            'action' => $this->action,
            'hostname' => $this->hostname,
            'reasons' => $this->reasons,
            'codes' => $this->codes,
        ];
    }
}
