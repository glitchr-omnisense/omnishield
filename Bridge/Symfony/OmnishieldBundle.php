<?php

namespace Omnishield\Bridge\Symfony;

use Omnishield\Akismet\AkismetGatewayFactory;
use Omnishield\Altcha\AltchaGatewayFactory;
use Omnishield\Bridge\Symfony\Controller\ChallengeController;
use Omnishield\Bridge\Symfony\EventListener\ScriptListener;
use Omnishield\Bridge\Symfony\Form\ChallengeType;
use Omnishield\Bridge\Symfony\Validator\PassesChallengeValidator;
use Omnishield\Bridge\Twig\OmnishieldExtension;
use Omnishield\ChallengeInterface;
use Omnishield\ClassifierInterface;
use Omnishield\Disposable\DisposableGatewayFactory;
use Omnishield\GatewayFactoryInterface;
use Omnishield\GatewayInterface;
use Omnishield\Recaptcha\RecaptchaGatewayFactory;
use Omnishield\Registry;
use Omnishield\Replay\CacheReplayStore;
use Omnishield\Replay\InMemoryReplayStore;
use Omnishield\Replay\ReplayStoreInterface;
use Omnishield\ReputationInterface;
use Omnishield\Stopforumspam\StopforumspamGatewayFactory;
use Omnishield\Testing\FixedGatewayFactory;
use Omnishield\Turnstile\TurnstileGatewayFactory;
use Omnishield\WidgetPrinter;
use Psr\Cache\CacheItemPoolInterface;
use Symfony\Component\Config\Definition\Configurator\DefinitionConfigurator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Bundle\AbstractBundle;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Validator\ConstraintValidator;
use Twig\Extension\AbstractExtension;

use function Symfony\Component\DependencyInjection\Loader\Configurator\service;
use function Symfony\Component\DependencyInjection\Loader\Configurator\tagged_iterator;

/**
 * Omnishield in a Symfony application: the gateway packages installed
 * (omnishield/altcha, turnstile, recaptcha, akismet, stopforumspam,
 * disposable) registered, the gateways built from configuration and
 * injectable by their name, and what a form needs around a captcha:
 *
 *     omnishield:
 *         gateways:
 *             forms: { factory: altcha, options: { hmac_key: '%env(ALTCHA_HMAC_KEY)%' } }
 *             comments: { factory: akismet, options: { api_key: '%env(AKISMET_KEY)%', site: 'https://example.org' } }
 *             emails: { factory: disposable }
 *         challenge:
 *             gateway: forms            # the ChallengeType's, the constraint's and omnishield_widget()'s default
 *             unreachable: reject       # or accept: a provider that does not answer lets the form through
 *         serve_scripts: true           # ALTCHA's widget served by the site (/omnishield/altcha/3.3.0/altcha.min.js), not a CDN
 *         replay:
 *             pool: cache.app           # where spent tokens are remembered; none: this process's memory
 *
 *     public function __construct(ChallengeInterface $forms, ClassifierInterface $comments, ReputationInterface $emails) {}
 *
 *     $builder->add('captcha', ChallengeType::class, ['action' => 'contact']);
 *     {{ omnishield_widget('forms', 'contact') }}
 *
 * Nothing is built, nor checked, when the container compiles: a gateway is
 * built the first time it is asked for, and an option left empty only shows
 * then (InvalidConfigException). A factory is autowired, the application's
 * http_client given to those that call a provider when it has one. An
 * application's own factories (a GatewayFactoryInterface) are registered
 * too, autoconfigured.
 *
 * Each piece is registered only when its component is installed - Form,
 * Validator, Routing and HttpFoundation, Twig: none is required by
 * omnisense/omnishield.
 */
final class OmnishieldBundle extends AbstractBundle
{
    protected string $extensionAlias = 'omnishield';

    /** The gateway packages this bundle knows, registered when installed; and the fixed gateway, for tests. */
    private const FACTORIES = [
        AltchaGatewayFactory::class,
        TurnstileGatewayFactory::class,
        RecaptchaGatewayFactory::class,
        AkismetGatewayFactory::class,
        StopforumspamGatewayFactory::class,
        DisposableGatewayFactory::class,
        FixedGatewayFactory::class,
    ];

    public function getPath(): string
    {
        return __DIR__;
    }

    public function configure(DefinitionConfigurator $definition): void
    {
        $definition->rootNode()
            ->children()
                ->arrayNode('gateways')
                    ->info('The gateways, by name: a factory (altcha, turnstile, recaptcha, akismet, stopforumspam, disposable, fixed...) and its options.')
                    ->useAttributeAsKey('name')
                    ->arrayPrototype()
                        ->children()
                            ->scalarNode('factory')->isRequired()->cannotBeEmpty()->end()
                            ->variableNode('options')->defaultValue([])->end()
                        ->end()
                    ->end()
                ->end()
                ->arrayNode('challenge')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->scalarNode('gateway')->defaultNull()->info('The captcha used when none is named: the first configured gateway when left empty.')->end()
                        ->enumNode('unreachable')->values(['reject', 'accept'])->defaultValue('reject')->info('When the provider does not answer: the form is refused (reject), or let through (accept).')->end()
                    ->end()
                ->end()
                ->booleanNode('serve_scripts')
                    ->defaultTrue()
                    ->info('Serve the widgets\' scripts the gateway packages ship from the site itself (ALTCHA\'s at /omnishield/altcha/<version>/altcha.min.js), and make it their gateways\' default: the page reaches nobody. false: the packages\' defaults (a CDN).')
                ->end()
                ->arrayNode('replay')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->scalarNode('service')->defaultNull()->info('A ReplayStoreInterface service of the application\'s: an atomic store (a unique key, SET NX).')->end()
                        ->scalarNode('pool')->defaultValue('cache.app')->info('Otherwise a PSR-6 cache pool, when the application has it; this process\'s memory when it has not.')->end()
                    ->end()
                ->end()
            ->end();
    }

    public function prependExtension(ContainerConfigurator $container, ContainerBuilder $builder): void
    {
        if ($builder->hasExtension('twig')) {
            $builder->prependExtensionConfig('twig', array_filter([
                'paths' => [__DIR__.'/templates' => 'Omnishield'],
                'form_themes' => class_exists(AbstractType::class) ? ['@Omnishield/form.html.twig'] : null,
            ]));
        }
    }

    /**
     * @param array{gateways: array<string, array{factory: string, options: array<string, mixed>}>, challenge: array{gateway: ?string, unreachable: string}, serve_scripts: bool, replay: array{service: ?string, pool: ?string}} $config
     */
    public function loadExtension(array $config, ContainerConfigurator $container, ContainerBuilder $builder): void
    {
        // The widgets' scripts from the site: ALTCHA's served by ScriptListener, and its gateways' default.
        $scripts = [];
        if (($config['serve_scripts'] ?? true) && class_exists(AltchaGatewayFactory::class) && \defined(AltchaGatewayFactory::class.'::SCRIPT_PATH')) {
            $scripts[AltchaGatewayFactory::SCRIPT_PATH] = AltchaGatewayFactory::SCRIPT_FILE;
            foreach ($config['gateways'] as $name => $gateway) {
                if ('altcha' === $gateway['factory'] && !\array_key_exists('script', (array) $gateway['options'])) {
                    $config['gateways'][$name]['options']['script'] = AltchaGatewayFactory::SCRIPT_PATH;
                }
            }
        }

        $builder->registerForAutoconfiguration(GatewayFactoryInterface::class)->addTag('omnishield.gateway_factory');
        $services = $container->services();

        if (null !== $config['replay']['service']) {
            $services->alias(ReplayStoreInterface::class, $config['replay']['service']);
        } else {
            $services->set(ReplayStoreInterface::class)
                ->factory([self::class, 'replayStore'])
                ->args([null !== $config['replay']['pool'] ? service($config['replay']['pool'])->nullOnInvalid() : null]);
        }

        foreach (self::FACTORIES as $factory) {
            if (!class_exists($factory) || !is_subclass_of($factory, GatewayFactoryInterface::class)) {
                continue;
            }
            $definition = $services->set($factory)->autowire()->tag('omnishield.gateway_factory');
            foreach ((new \ReflectionClass($factory))->getConstructor()?->getParameters() ?? [] as $parameter) {
                if ('http' === $parameter->getName()) {
                    $definition->arg('$http', service('http_client')->nullOnInvalid());
                }
            }
        }

        $services->set(Registry::class)
            ->args([tagged_iterator('omnishield.gateway_factory'), $config['gateways']])
            ->public();

        foreach (array_keys($config['gateways']) as $name) {
            $id = 'omnishield.gateway.'.$name;
            $services->set($id, GatewayInterface::class)->factory([service(Registry::class), 'get'])->args([$name]);
            foreach ([GatewayInterface::class, ChallengeInterface::class, ClassifierInterface::class, ReputationInterface::class] as $type) {
                $builder->registerAliasForArgument($id, $type, $name);
            }
        }

        $default = $config['challenge']['gateway'] ?? array_key_first($config['gateways']);
        $builder->setParameter('omnishield.challenge.gateway', $default);
        $services->set(WidgetPrinter::class)->tag('kernel.reset', ['method' => 'reset']);

        // The widgets in the visitor's language: the request's locale, the domain "omnishield" (translations/).
        $services->set(WidgetLocalizer::class)
            ->args([service('translator')->nullOnInvalid(), service('request_stack')->nullOnInvalid()])
            ->public();

        if (class_exists(AbstractType::class)) {
            $services->set(ChallengeType::class)
                ->args([service(Registry::class), service(WidgetPrinter::class), service('request_stack')->nullOnInvalid(), $default, service(WidgetLocalizer::class)])
                ->tag('form.type');
        }
        if (class_exists(ConstraintValidator::class)) {
            $services->set(PassesChallengeValidator::class)
                ->args([service(Registry::class), service('request_stack')->nullOnInvalid(), $default, 'accept' === $config['challenge']['unreachable']])
                ->tag('validator.constraint_validator');
        }
        if (class_exists(Route::class) && class_exists(JsonResponse::class)) {
            $services->set(ChallengeController::class)
                ->args([service(Registry::class)])
                ->tag('controller.service_arguments')
                ->public();
        }
        if ([] !== $scripts && class_exists(BinaryFileResponse::class)) {
            $services->set(ScriptListener::class)
                ->args([$scripts])
                ->tag('kernel.event_listener', ['event' => 'kernel.request', 'priority' => 256])
                ->public();
        }
        if (class_exists(AbstractExtension::class)) {
            $services->set(OmnishieldExtension::class)
                ->args([service(Registry::class), service(WidgetPrinter::class), $default, service(WidgetLocalizer::class)])
                ->tag('twig.extension');
        }
    }

    /** The store of spent tokens: the pool when there is one, this process's memory otherwise. */
    public static function replayStore(?object $pool = null): ReplayStoreInterface
    {
        return $pool instanceof CacheItemPoolInterface ? new CacheReplayStore($pool) : new InMemoryReplayStore();
    }
}
