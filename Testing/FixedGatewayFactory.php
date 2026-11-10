<?php

namespace Omnishield\Testing;

use Omnishield\Config;
use Omnishield\Exception\InvalidConfigException;
use Omnishield\GatewayFactory;

/**
 * The fixed gateway, by configuration - for an application's test
 * environment:
 *
 *   options:
 *     pass: true                 # false: every token, submission and identity is refused
 */
final class FixedGatewayFactory extends GatewayFactory
{
    /** @param array<string, mixed> $options */
    public function create(array $options = []): FixedGateway
    {
        $gateway = parent::create($options);

        return $gateway instanceof FixedGateway ? $gateway : throw new InvalidConfigException('The "fixed" factory built another gateway.');
    }

    protected function populate(Config $c): void
    {
        $c->defaults([
            'omnishield.factory_name' => 'fixed',
            'omnishield.factory_title' => 'Fixed',
            'omnishield.required_options' => [],
            'pass' => true,
        ]);
    }

    protected function build(Config $c): FixedGateway
    {
        return new FixedGateway($c->bool('pass'));
    }
}
