<?php

namespace Omnishield\Policy;

/**
 * A localizable widget behind the guard: the same guard over the localized
 * gateway.
 *
 * @internal
 */
trait LocalizesThroughGuard
{
    public function texts(): array
    {
        return $this->gateway->texts();
    }

    public function language(): ?string
    {
        return $this->gateway->language();
    }

    public function localized(string $language, array $strings = []): static
    {
        return new self($this->gateway->localized($language, $strings), $this->guard);
    }
}
