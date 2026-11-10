<?php

namespace Omnishield\Replay;

/**
 * Spent tokens in this process's memory: for tests, a worker that handles
 * every request, a script. Behind PHP-FPM each request starts with an empty
 * store - use a shared one there (CacheReplayStore, your database).
 */
final class InMemoryReplayStore implements ReplayStoreInterface
{
    /** @var array<string, int> the spent tokens and until when they are kept */
    private array $spent = [];

    /** @param (\Closure(): int)|null $clock the time now, as a Unix timestamp; time() when null */
    public function __construct(private readonly ?\Closure $clock = null)
    {
    }

    public function spend(string $id, \DateTimeImmutable $until): bool
    {
        $now = $this->clock ? ($this->clock)() : time();
        $this->spent = array_filter($this->spent, static fn (int $expires) => $expires > $now);
        if (isset($this->spent[$id])) {
            return false;
        }
        $this->spent[$id] = max($until->getTimestamp(), $now + 1);

        return true;
    }

    public function count(): int
    {
        return \count($this->spent);
    }
}
