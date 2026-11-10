<?php

namespace Omnishield;

use Omnisense\Contracts\Provider\Config as BaseConfig;
use Omnisense\Contracts\Provider\ProviderFactory;
use Omnishield\Exception\InvalidConfigException;
use Omnishield\Exception\UsageLimitReachedException;

/**
 * The Omnibus way, at its simplest: a gateway's factory fills a Config - its
 * name ("omnishield.factory_name"), its title ("omnishield.factory_title"),
 * the options it needs ("omnishield.required_options") and the defaults of
 * the others - then builds the gateway from it. omnisense/contracts' factory
 * underneath gives the Config the gateway's Backoff (Config::backoff(), for
 * Http\Answer::send()) and, once built, its PolicyGuard (guard()).
 *
 * Nothing here calls anything: a factory whose provider is reached over HTTP
 * takes its client in its own constructor - the application's, a
 * MockHttpClient in a test - and one that keeps something between requests
 * (a spent token) takes its store there too.
 */
abstract class GatewayFactory extends ProviderFactory implements GatewayFactoryInterface
{
    public function create(array $options = []): GatewayInterface
    {
        $gateway = parent::create($options);

        return $gateway instanceof GatewayInterface ? $gateway : throw new InvalidConfigException(\sprintf('The "%s" factory built no omnishield gateway.', $this->getName()));
    }

    /** @param array<string, mixed> $options */
    public function createConfig(array $options = []): Config
    {
        return self::own(parent::createConfig($options));
    }

    /** The factory's name and title, its required options, the defaults of the others. */
    abstract protected function populate(Config $c): void;

    /** The gateway, from a Config that holds everything it needs. */
    abstract protected function build(Config $c): GatewayInterface;

    protected function newConfig(array $options): Config
    {
        return new Config($options);
    }

    protected function limitException(): string
    {
        return UsageLimitReachedException::class;
    }

    final protected function configure(BaseConfig $c): void
    {
        $this->populate(self::own($c));
    }

    final protected function make(BaseConfig $c): GatewayInterface
    {
        return $this->build(self::own($c));
    }

    private static function own(BaseConfig $c): Config
    {
        return $c instanceof Config ? $c : throw new InvalidConfigException('An omnishield factory builds from omnishield\'s Config.');
    }
}
