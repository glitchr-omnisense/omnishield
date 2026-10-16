<?php

namespace Omnishield\Bridge\Symfony\Form;

use Omnishield\Bridge\Symfony\Validator\PassesChallenge;
use Omnishield\Bridge\Symfony\WidgetLocalizer;
use Omnishield\Registry;
use Omnishield\WidgetPrinter;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\FormEvent;
use Symfony\Component\Form\FormEvents;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\Form\FormView;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\OptionsResolver\Options;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * A captcha in a form: a field mapped to nothing that renders the gateway's
 * widget and carries the PassesChallenge constraint.
 *
 *     $builder->add('captcha', ChallengeType::class, ['action' => 'contact']);
 *     $builder->add('captcha', ChallengeType::class, ['gateway' => 'turnstile', 'hostname' => true]);
 *
 * A provider's widget posts its token under its own name ("altcha",
 * "cf-turnstile-response"), outside the form's: the field reads it from the
 * request when the form's own data does not hold it.
 */
final class ChallengeType extends AbstractType
{
    public function __construct(
        private readonly Registry $registry,
        private readonly WidgetPrinter $printer,
        private readonly ?RequestStack $requests = null,
        private readonly ?string $gateway = null,
        private readonly ?WidgetLocalizer $localizer = null,
    ) {
    }

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder->addEventListener(FormEvents::PRE_SUBMIT, function (FormEvent $event) use ($options): void {
            if (\is_string($event->getData()) && '' !== $event->getData()) {
                return;
            }
            $field = $this->registry->challenge($options['gateway'])->widget($options['action'])->field;
            $request = $this->requests?->getCurrentRequest();
            $token = $request?->request->all()[$field] ?? null;
            $event->setData(\is_string($token) ? $token : '');
        });
    }

    public function buildView(FormView $view, FormInterface $form, array $options): void
    {
        // In the visitor's language, with the bridge's texts (WidgetLocalizer).
        $challenge = $this->registry->challenge($options['gateway']);
        $widget = ($this->localizer?->localize($challenge) ?? $challenge)->widget($options['action']);
        $view->vars['omnishield_widget'] = $widget;
        // Printed when the field is: a view built and thrown away (or a field taken out of it)
        // does not take the provider's script away from the view the page shows.
        $view->vars['omnishield_html'] = $this->printer->later($widget, $options['nonce']);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'gateway' => $this->gateway,
            'action' => null,
            'hostname' => null,
            'nonce' => null,
            'mapped' => false,
            'compound' => false,
            'required' => false,
            'label' => false,
            'error_bubbling' => false,
            'constraints' => static fn (Options $options) => [new PassesChallenge(gateway: $options['gateway'], action: $options['action'], hostname: $options['hostname'])],
        ]);
        $resolver->setAllowedTypes('gateway', 'string');
        $resolver->setAllowedTypes('action', ['null', 'string']);
        $resolver->setAllowedTypes('hostname', ['null', 'bool', 'string']);
        $resolver->setAllowedTypes('nonce', ['null', 'string']);
        $resolver->setInfo('gateway', 'The configured captcha (omnishield.gateways.<name>); omnishield.challenge.gateway by default.');
        $resolver->setInfo('action', 'What the form is for: signed into the token where the provider can, checked back.');
        $resolver->setInfo('hostname', 'The host the widget must have been shown on: a host, true for the request\'s, null to leave it.');
        $resolver->setInfo('nonce', 'The page\'s Content-Security-Policy nonce, for the widget\'s scripts.');
    }

    public function getBlockPrefix(): string
    {
        return 'omnishield_challenge';
    }
}
