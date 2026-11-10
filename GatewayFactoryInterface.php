<?php

namespace Omnishield;

use Omnisense\Contracts\Provider\ProviderFactoryInterface;

/** Builds a gateway from its options (a site key, a secret, a threshold...). */
interface GatewayFactoryInterface extends ProviderFactoryInterface
{
    /** The name gateways are configured with: "altcha", "turnstile"... */
    public function getName(): string;

    /** @param array<string, mixed> $options */
    public function create(array $options = []): GatewayInterface;
}
