<?php

namespace Omnishield\Tests\Bridge;

use Omnishield\Altcha\AltchaGateway;
use Omnishield\Altcha\AltchaGatewayFactory;
use Omnishield\Bridge\Symfony\Form\ChallengeType;
use Omnishield\Bridge\Symfony\OmnishieldBundle;
use Omnishield\Bridge\Symfony\WidgetLocalizer;
use Omnishield\Bridge\Twig\OmnishieldExtension;
use Omnishield\Registry;
use Omnishield\Testing\FixedGateway;
use Omnishield\WidgetPrinter;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\Form\Forms;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Translation\Loader\ArrayLoader;
use Symfony\Component\Translation\Loader\YamlFileLoader;
use Symfony\Component\Translation\Translator;
use Symfony\Component\Yaml\Yaml;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * The widget in the visitor's language: the bridge's catalogues, read as
 * FrameworkBundle reads them, the request's locale, the gateway's options.
 */
final class WidgetLocalizerTest extends TestCase
{
    private const LANGUAGES = ['fr', 'en', 'de', 'ja'];

    protected function setUp(): void
    {
        if (!class_exists(AltchaGatewayFactory::class)) {
            self::markTestSkipped('omnishield/altcha is not installed.');
        }
    }

    private static function catalogue(string $language): string
    {
        return (new OmnishieldBundle())->getPath().'/translations/'.WidgetLocalizer::DOMAIN.'.'.$language.'.yaml';
    }

    private static function translator(): Translator
    {
        $translator = new Translator('en');
        $translator->addLoader('yaml', new YamlFileLoader());
        foreach (self::LANGUAGES as $language) {
            $translator->addResource('yaml', self::catalogue($language), $language, WidgetLocalizer::DOMAIN);
        }

        return $translator;
    }

    private static function requests(string $locale): RequestStack
    {
        $request = Request::create('/contact');
        $request->setLocale($locale);
        $requests = new RequestStack();
        $requests->push($request);

        return $requests;
    }

    /** @param array<string, mixed> $options */
    private static function altcha(array $options = []): AltchaGateway
    {
        $gateway = (new AltchaGatewayFactory())->create($options + ['hmac_key' => 'a-long-secret', 'cost' => 10]);
        self::assertInstanceOf(AltchaGateway::class, $gateway);

        return $gateway;
    }

    /** @return array<string, string> the texts the widget's script registers */
    private static function registered(?string $inline): array
    {
        self::assertNotNull($inline);
        self::assertSame(1, preg_match('/\}\)\(("[^"]*"),(\{.*\})\);$/s', $inline, $found), $inline);

        return ['@language' => json_decode($found[1], true)] + json_decode($found[2], true);
    }

    public function testEveryTextIsTranslatedInEveryLanguageWithoutTutoiement(): void
    {
        foreach (self::LANGUAGES as $language) {
            $texts = Yaml::parseFile(self::catalogue($language))['altcha'] ?? [];
            self::assertSame([], array_diff(AltchaGateway::TEXTS, array_keys($texts)), $language.': missing');
            self::assertSame([], array_diff(array_keys($texts), AltchaGateway::TEXTS), $language.': unknown to the widget');
            foreach ($texts as $name => $text) {
                self::assertIsString($text);
                self::assertNotSame('', trim($text), $language.'.'.$name);
            }
            self::assertStringContainsString('https://altcha.org/', $texts['footer'], $language.': the footer still links ALTCHA');
        }

        $french = Yaml::parseFile(self::catalogue('fr'))['altcha'];
        self::assertSame('Je ne suis pas un robot', $french['label']);
        foreach ($french as $name => $text) {
            self::assertDoesNotMatchRegularExpression("/\\b(tu|te|toi|ton|ta|tes|t')\\b/iu", strip_tags($text), 'fr.'.$name.': the visitor is addressed as "vous"');
        }
    }

    public function testTheWidgetSpeaksTheRequestsLanguage(): void
    {
        $translator = self::translator();

        $french = self::registered((new WidgetLocalizer($translator, self::requests('fr')))->localize(self::altcha())->widget('contact')->inline);
        self::assertSame(['fr', 'Je ne suis pas un robot', 'Vérification…', 'Vérifié', 'Échec de la vérification, réessayez.'], [$french['@language'], $french['label'], $french['verifying'], $french['verified'], $french['error']]);
        self::assertCount(\count(AltchaGateway::TEXTS) + 1, $french);

        $widget = (new WidgetLocalizer($translator, self::requests('de_CH')))->localize(self::altcha())->widget();
        self::assertSame('de', $widget->attributes['language'], 'no texts of de_CH\'s own: the widget is told German');
        self::assertSame('Ich bin kein Roboter', self::registered($widget->inline)['label'], 'the catalogue falls back from de_CH to de');

        self::assertSame('ja', self::registered((new WidgetLocalizer($translator, self::requests('ja')))->localize(self::altcha())->widget()->inline)['@language']);
        self::assertSame('en', self::registered((new WidgetLocalizer($translator, self::requests('en')))->localize(self::altcha())->widget()->inline)['@language']);

        // A language no catalogue holds: the widget's own texts, nothing of ours.
        $portuguese = (new WidgetLocalizer($translator, self::requests('pt')))->localize(self::altcha())->widget();
        self::assertSame('pt', $portuguese->attributes['language']);
        self::assertNull($portuguese->inline);
    }

    public function testARegionalLocaleIsToldAsItsLanguageUnlessTheRegionHasTextsOfItsOwn(): void
    {
        $translator = self::translator();

        // fr_BE, as glitchr/omnibase's core sets it: no Belgian catalogue, so "fr" and the French texts.
        $belgian = (new WidgetLocalizer($translator, self::requests('fr_BE')))->localize(self::altcha())->widget();
        self::assertSame('fr', $belgian->attributes['language']);
        self::assertSame(['fr', 'Je ne suis pas un robot'], [self::registered($belgian->inline)['@language'], self::registered($belgian->inline)['label']], 'registered under the language the widget is told');

        // A region with words of its own: the widget keeps it, and gets those words over the language's.
        $translator->addLoader('array', new ArrayLoader());
        $translator->addResource('array', ['altcha' => ['label' => 'Je ne suis pas un robot, une fois']], 'fr_BE', WidgetLocalizer::DOMAIN);
        $belgian = (new WidgetLocalizer($translator, self::requests('fr_BE')))->localize(self::altcha())->widget();
        self::assertSame('fr-be', $belgian->attributes['language']);
        $texts = self::registered($belgian->inline);
        self::assertSame(['fr-be', 'Je ne suis pas un robot, une fois', 'Vérification…'], [$texts['@language'], $texts['label'], $texts['verifying']], 'its own label, the language\'s other texts');

        // A regional locale of a language no catalogue holds: the language, nothing of ours.
        $brazilian = (new WidgetLocalizer($translator, self::requests('pt_BR')))->localize(self::altcha())->widget();
        self::assertSame('pt', $brazilian->attributes['language']);
        self::assertNull($brazilian->inline);

        // The gateway's own language is never shortened: it is what the site asked.
        self::assertSame('fr-ca', (new WidgetLocalizer($translator, self::requests('en')))->localize(self::altcha(['language' => 'fr-CA']))->widget()->attributes['language']);
    }

    public function testTheGatewaysOwnLanguageAndTextsWin(): void
    {
        $localizer = new WidgetLocalizer(self::translator(), self::requests('en'));

        $texts = self::registered($localizer->localize(self::altcha(['strings' => ['label' => 'Pas un robot, promis']]))->widget()->inline);
        self::assertSame(['en', 'Pas un robot, promis', 'Verifying…'], [$texts['@language'], $texts['label'], $texts['verifying']]);

        $texts = self::registered($localizer->localize(self::altcha(['language' => 'fr']))->widget()->inline);
        self::assertSame(['fr', 'Je ne suis pas un robot'], [$texts['@language'], $texts['label']], 'a site in one language whatever the visitor\'s');
    }

    public function testWithoutATranslatorOrForAGatewayWithoutTextsNothingChanges(): void
    {
        $altcha = self::altcha();
        self::assertSame($altcha, (new WidgetLocalizer(null, self::requests('fr')))->localize($altcha));
        $fixed = new FixedGateway();
        self::assertSame($fixed, (new WidgetLocalizer(self::translator(), self::requests('fr')))->localize($fixed));
    }

    public function testTheFormFieldPrintsTheWidgetInTheVisitorsLanguage(): void
    {
        $requests = self::requests('fr');
        $registry = new Registry([new AltchaGatewayFactory()], ['forms' => ['factory' => 'altcha', 'options' => ['hmac_key' => 'a-long-secret', 'cost' => 10]]]);
        $forms = Forms::createFormFactoryBuilder()
            ->addType(new ChallengeType($registry, new WidgetPrinter(), $requests, 'forms', new WidgetLocalizer(self::translator(), $requests)))
            ->getFormFactory();

        $view = $forms->createNamedBuilder('contact')->add('captcha', ChallengeType::class, ['action' => 'contact'])->getForm()->createView();
        self::assertStringContainsString('"label":"Je ne suis pas un robot"', $view['captcha']->vars['omnishield_html']);
        self::assertStringContainsString(' language="fr"', $view['captcha']->vars['omnishield_html']);
    }

    public function testTheBundlePrintsTheWidgetInTheVisitorsLanguage(): void
    {
        self::assertFileExists(self::catalogue('fr'), 'where FrameworkBundle looks for a bundle\'s translations');

        $container = new ContainerBuilder();
        $container->register('http_client', MockHttpClient::class)->setPublic(true);
        $container->setAlias(HttpClientInterface::class, 'http_client');
        $container->register('request_stack', RequestStack::class)->setSynthetic(true)->setPublic(true);
        $container->register('translator', Translator::class)->setSynthetic(true)->setPublic(true);
        $bundle = new OmnishieldBundle();
        $container->registerExtension($bundle->getContainerExtension());
        $container->loadFromExtension('omnishield', ['gateways' => ['forms' => ['factory' => 'altcha', 'options' => ['hmac_key' => 'a-long-secret', 'cost' => 10]]]]);
        // What Twig is given, reached here as Twig reaches it.
        $container->addCompilerPass(new class implements CompilerPassInterface {
            public function process(ContainerBuilder $container): void
            {
                $container->getDefinition(OmnishieldExtension::class)->setPublic(true);
            }
        });
        $container->compile();
        $container->set('request_stack', self::requests('fr'));
        $container->set('translator', self::translator());

        $extension = $container->get(OmnishieldExtension::class);
        self::assertInstanceOf(OmnishieldExtension::class, $extension);
        $html = $extension->widget('forms', 'contact');
        self::assertStringContainsString(' language="fr"', $html);
        self::assertStringContainsString('"label":"Je ne suis pas un robot"', $html);

        $widget = $container->get(WidgetLocalizer::class)->localize($container->get(Registry::class)->challenge('forms'))->widget();
        self::assertSame('Vérifié', self::registered($widget->inline)['verified']);
    }
}
