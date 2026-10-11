<?php

namespace Omnishield\Bridge\Symfony;

use Omnishield\ChallengeInterface;
use Omnishield\LocalizableInterface;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Translation\TranslatorBagInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * A captcha's widget in the visitor's language: for a gateway that takes
 * texts (LocalizableInterface), the request's locale - or the language the
 * gateway was given - and the texts of the
 * bridge's translation domain "omnishield" - "<gateway>.<text>", French,
 * English, German and Japanese shipped, an application's own catalogue
 * over them -, the gateway's own `strings` option winning over both. A text
 * the catalogue does not hold is left to the widget. A regional locale
 * ("fr_BE") without texts of its own is told to the widget as its language
 * ("fr"), the texts being the language's.
 */
final class WidgetLocalizer
{
    public const DOMAIN = 'omnishield';

    public function __construct(
        private readonly ?TranslatorInterface $translator = null,
        private readonly ?RequestStack $requests = null,
    ) {
    }

    public function localize(ChallengeInterface $gateway): ChallengeInterface
    {
        if (!$gateway instanceof LocalizableInterface || null === $this->translator) {
            return $gateway;
        }
        $locale = $gateway->language()
            ?? $this->requests?->getCurrentRequest()?->getLocale()
            ?? (method_exists($this->translator, 'getLocale') ? $this->translator->getLocale() : null);
        if (null === $locale || '' === $locale) {
            return $gateway;
        }

        $strings = [];
        foreach ($gateway->texts() as $text) {
            $id = $gateway->getName().'.'.$text;
            $translated = $this->translator->trans($id, [], self::DOMAIN, $locale);
            if ($translated !== $id && '' !== $translated) {
                $strings[$text] = $translated;
            }
        }

        return $gateway->localized($this->language($gateway, $locale), $strings);
    }

    /**
     * The language the widget is told: the locale whole ("fr_BE") when a
     * catalogue of that region holds texts of its own, the language alone
     * ("fr") otherwise - the texts it gets are then the language's, found
     * through the translator's fallback, and the widget's own words for
     * that language, if it has any, rather than a region it never heard of.
     */
    private function language(LocalizableInterface $gateway, string $locale): string
    {
        $language = (string) preg_split('/[-_]/', $locale, 2)[0];
        if ('' === $language || $language === $locale || !$this->translator instanceof TranslatorBagInterface) {
            return $locale;
        }
        $own = $this->translator->getCatalogue($locale);
        foreach ($gateway->texts() as $text) {
            if ($own->defines($gateway->getName().'.'.$text, self::DOMAIN)) {
                return $locale;
            }
        }

        return $language;
    }
}
