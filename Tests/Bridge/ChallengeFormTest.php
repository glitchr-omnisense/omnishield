<?php

namespace Omnishield\Tests\Bridge;

use Omnishield\Bridge\Symfony\Form\ChallengeType;
use Omnishield\Bridge\Symfony\Validator\PassesChallenge;
use Omnishield\Bridge\Symfony\Validator\PassesChallengeValidator;
use Omnishield\Bridge\Twig\OmnishieldExtension;
use Omnishield\Registry;
use Omnishield\Replay\InMemoryReplayStore;
use Omnishield\Testing\FixedGateway;
use Omnishield\Testing\FixedGatewayFactory;
use Omnishield\Tests\StubFactory;
use Omnishield\WidgetPrinter;
use PHPUnit\Framework\TestCase;
use Symfony\Bridge\Twig\Extension\FormExtension;
use Symfony\Bridge\Twig\Extension\TranslationExtension;
use Symfony\Bridge\Twig\Form\TwigRendererEngine;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\Extension\HttpFoundation\HttpFoundationExtension;
use Symfony\Component\Form\Extension\Validator\ValidatorExtension;
use Symfony\Component\Form\FormFactoryInterface;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\Form\FormRenderer;
use Symfony\Component\Form\Forms;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\ConstraintValidatorFactory;
use Symfony\Component\Validator\ConstraintValidatorInterface;
use Symfony\Component\Validator\Validation;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;
use Twig\RuntimeLoader\FactoryRuntimeLoader;

/**
 * A contact form with a captcha, submitted as a browser posts it: the
 * widget's token outside the form's own fields.
 */
final class ChallengeFormTest extends TestCase
{
    private RequestStack $requests;
    private Registry $registry;

    private function factory(bool $acceptUnreachable = false): FormFactoryInterface
    {
        $this->requests = new RequestStack();
        $this->registry ??= new Registry([new StubFactory(new InMemoryReplayStore()), new FixedGatewayFactory()], [
            'forms' => ['factory' => 'stub', 'options' => ['secret' => 's']],
            'tests' => ['factory' => 'fixed'],
        ]);
        $validator = new PassesChallengeValidator($this->registry, $this->requests, 'forms', $acceptUnreachable);
        $validators = new class($validator) extends ConstraintValidatorFactory {
            public function __construct(private readonly PassesChallengeValidator $validator)
            {
                parent::__construct();
            }

            public function getInstance(Constraint $constraint): ConstraintValidatorInterface
            {
                return $constraint instanceof PassesChallenge ? $this->validator : parent::getInstance($constraint);
            }
        };

        return Forms::createFormFactoryBuilder()
            ->addExtension(new HttpFoundationExtension())
            ->addExtension(new ValidatorExtension(Validation::createValidatorBuilder()->setConstraintValidatorFactory($validators)->getValidator()))
            ->addType(new ChallengeType($this->registry, new WidgetPrinter(), $this->requests, 'forms'))
            ->getFormFactory();
    }

    /** @param array<string, string> $posted the request's fields beside the form's */
    private function submit(FormFactoryInterface $factory, array $posted, array $options = []): FormInterface
    {
        $form = $factory->createNamedBuilder('contact')
            ->add('message', TextType::class)
            ->add('captcha', ChallengeType::class, $options + ['action' => 'contact'])
            ->getForm();
        $request = Request::create('https://example.org/contact', 'POST', ['contact' => ['message' => 'Hello'], ...$posted], server: ['REMOTE_ADDR' => '192.0.2.10']);
        $this->requests->push($request);
        $form->handleRequest($request);
        $this->requests->pop();

        return $form;
    }

    /** @return list<string> */
    private static function errors(FormInterface $form): array
    {
        return array_map(static fn ($error) => $error->getMessage(), iterator_to_array($form->get('captcha')->getErrors()));
    }

    public function testAValidTokenPassesOnceAndIsRefusedWhenReplayed(): void
    {
        $factory = $this->factory();

        $form = $this->submit($factory, ['stub-token' => 'good-1']);
        self::assertTrue($form->isSubmitted());
        self::assertTrue($form->isValid(), implode(', ', self::errors($form)));
        self::assertSame(['message' => 'Hello'], $form->getData(), 'the field is mapped to nothing');

        $replayed = $this->submit($factory, ['stub-token' => 'good-1']);
        self::assertFalse($replayed->isValid());
        self::assertSame(['The check that you are not a robot did not pass. Please try again.'], self::errors($replayed));
        $violation = $replayed->get('captcha')->getErrors()[0]->getCause();
        self::assertSame(PassesChallenge::FAILED_ERROR, $violation->getCode());
        self::assertTrue($violation->getCause()->failedFor('duplicate'), 'the verdict, for whoever wants the reason');
    }

    public function testAMissingOrForgedTokenIsRefused(): void
    {
        $factory = $this->factory();

        self::assertSame(['Please confirm that you are not a robot.'], self::errors($this->submit($factory, [])));
        self::assertSame(['The check that you are not a robot did not pass. Please try again.'], self::errors($this->submit($factory, ['stub-token' => 'forged'])));
    }

    public function testAProviderThatDoesNotAnswerRefusesUnlessToldToAccept(): void
    {
        self::assertSame(['The check that you are not a robot could not be done just now. Please try again in a moment.'], self::errors($this->submit($this->factory(), ['stub-token' => 'down'])));
        self::assertTrue($this->submit($this->factory(acceptUnreachable: true), ['stub-token' => 'down'])->isValid());
    }

    public function testTheFixedGatewaysOwnFieldCarriesItsToken(): void
    {
        $factory = $this->factory();

        self::assertTrue($this->submit($factory, [FixedGateway::FIELD => FixedGateway::TOKEN], ['gateway' => 'tests'])->isValid());
        self::assertFalse($this->submit($factory, [], ['gateway' => 'tests'])->isValid());
    }

    public function testTheFieldRendersTheWidgetAndTheTwigFunctionPrintsItsScriptOnce(): void
    {
        $factory = $this->factory();
        $twig = new Environment(new FilesystemLoader([__DIR__.'/../../Bridge/Symfony/templates', \dirname((string) (new \ReflectionClass(FormExtension::class))->getFileName(), 2).'/Resources/views/Form']));
        $engine = new TwigRendererEngine(['form_div_layout.html.twig', 'form.html.twig'], $twig);
        $twig->addRuntimeLoader(new FactoryRuntimeLoader([FormRenderer::class => static fn () => new FormRenderer($engine)]));
        $twig->addExtension(new FormExtension());
        $twig->addExtension(new TranslationExtension());
        $twig->addExtension(new OmnishieldExtension($this->registry, new WidgetPrinter(), 'forms'));
        $form = $factory->createNamedBuilder('contact')->add('captcha', ChallengeType::class, ['action' => 'contact'])->getForm();

        $html = $twig->createTemplate('{{ form_widget(form.captcha) }}')->render(['form' => $form->createView()]);
        self::assertSame('<script src="/stub.js" type="module"></script><stub-widget data-action="contact"></stub-widget>', trim($html));

        $html = $twig->createTemplate('{{ omnishield_widget() }}|{{ omnishield_widget(action: "signup") }}|{{ omnishield_widget_data("tests").field }}')->render();
        self::assertSame('<script src="/stub.js" type="module"></script><stub-widget></stub-widget>|<stub-widget data-action="signup"></stub-widget>|omnishield-token', $html);
    }

    public function testAViewBuiltAndNotShownLeavesTheScriptToTheOneThatIs(): void
    {
        $factory = $this->factory();
        $twig = new Environment(new FilesystemLoader([__DIR__.'/../../Bridge/Symfony/templates', \dirname((string) (new \ReflectionClass(FormExtension::class))->getFileName(), 2).'/Resources/views/Form']));
        $engine = new TwigRendererEngine(['form_div_layout.html.twig', 'form.html.twig'], $twig);
        $twig->addRuntimeLoader(new FactoryRuntimeLoader([FormRenderer::class => static fn () => new FormRenderer($engine)]));
        $twig->addExtension(new FormExtension());
        $twig->addExtension(new TranslationExtension());
        $form = $factory->createNamedBuilder('login')->add('captcha', ChallengeType::class, ['action' => 'login'])->getForm();

        // A view made and thrown away first (omnibase's FormProcessor makes one to collect the page's
        // assets), then the one the page shows: the widget there comes with its script.
        $form->createView();
        $html = $twig->createTemplate('{{ form_widget(form.captcha) }}')->render(['form' => $form->createView()]);
        self::assertSame('<script src="/stub.js" type="module"></script><stub-widget data-action="login"></stub-widget>', trim($html));

        // Two forms on the page shown: the script once, with the first printed.
        $other = $factory->createNamedBuilder('contact')->add('captcha', ChallengeType::class, ['action' => 'contact'])->getForm();
        $html = $twig->createTemplate('{{ form_widget(other.captcha) }}')->render(['other' => $other->createView()]);
        self::assertSame('<stub-widget data-action="contact"></stub-widget>', trim($html));
    }
}
