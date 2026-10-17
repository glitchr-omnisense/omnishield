---
title: Symfony
order: 4
---

# Symfony

Omnishield runs without a framework ([installation](installation.md)); in a Symfony application its
bridge does the wiring. Its components - `symfony/config`, `symfony/dependency-injection`,
`symfony/http-kernel`, and for the pieces below `symfony/form`, `symfony/validator`,
`symfony/routing`, `symfony/http-foundation`, `twig/twig` - are not required by
`omniscient/omnishield`: the application has them, each piece is registered only when its component
is installed, and nothing of them is loaded outside Symfony.

Register `Omnishield\Bridge\Symfony\OmnishieldBundle` (no Flex recipe):

```php
// config/bundles.php
return [
    // ...
    Omnishield\Bridge\Symfony\OmnishieldBundle::class => ['all' => true],
];
```

```yaml
# config/packages/omnishield.yaml
omnishield:
    gateways:                                  # by name: a factory and its options
        forms:
            factory: altcha
            options: { hmac_key: '%env(ALTCHA_HMAC_KEY)%' }
        comments:
            factory: akismet
            options: { api_key: '%env(AKISMET_API_KEY)%', site: 'https://example.org' }
        emails:
            factory: disposable
            options: { allow: '%env(default::DISPOSABLE_ALLOW)%' }
    challenge:
        gateway: forms                         # the default captcha; the first gateway when left out
        unreachable: reject                    # or accept: a provider that does not answer lets the form through
    serve_scripts: true                        # ALTCHA's widget served by the site, not a CDN
    replay:
        pool: cache.app                        # where spent tokens are remembered (PSR-6)
        # service: App\Security\SpentTokens   # or a ReplayStoreInterface of yours, atomic

when@test:
    omnishield:
        gateways:
            forms: { factory: fixed }          # passes every token but an empty one; { pass: false } refuses all
```

Every `omnishield/*` package installed registers its factory, **autowired**: the application's
`http_client` is given to those that call a provider (none: one of their own), the store of spent
tokens to ALTCHA. An application's own gateway - a class implementing `GatewayFactoryInterface` -
is registered too, autoconfigured, and can be named as a `factory`. `fixed`
(`Testing\FixedGateway`) is always there, for tests.

What is autowired:

| Service | |
|---|---|
| `ChallengeInterface $forms`, `ClassifierInterface $comments`, `ReputationInterface $emails`, `GatewayInterface $forms` | one gateway by the argument's name (the configured name) |
| `Registry` | every configured gateway by name: `get()`, `challenge()`, `classifier()`, `reputation()`, `has()`, `names()`, `options()`, `create()` |
| `ReplayStoreInterface` | the store of spent tokens: `replay.service`, else a `CacheReplayStore` on `replay.pool` when the application has it, else this process's memory |

Nothing is built when the container compiles: a gateway is built the first time it is asked
for, and an option left empty only shows then (`InvalidConfigException`).

## A captcha in a form

```php
use Omnishield\Bridge\Symfony\Form\ChallengeType;

$builder
    ->add('message', TextareaType::class)
    ->add('captcha', ChallengeType::class, ['action' => 'contact']);
```

`ChallengeType` is mapped to nothing: it renders the gateway's widget (its own form theme,
registered with Twig by the bundle) and carries the `PassesChallenge` constraint. A provider's
widget posts its token under its own name (`altcha`, `cf-turnstile-response`), outside the form's
fields: the field reads it from the request when the form's own data does not hold it.

| Option | Default | |
|---|---|---|
| `gateway` | `omnishield.challenge.gateway` | the configured captcha |
| `action` | `null` | what the form is for: signed into the token where the provider can, checked back |
| `hostname` | `null` | the host the widget must have been shown on: a host, `true` for the request's |
| `nonce` | `null` | the page's Content-Security-Policy nonce, for the widget's scripts |

The errors land on the field:

| Code | Message (to translate in `validators`) |
|---|---|
| `PassesChallenge::MISSING_ERROR` | Please confirm that you are not a robot. |
| `PassesChallenge::FAILED_ERROR` | The check that you are not a robot did not pass. Please try again. |
| `PassesChallenge::UNREACHABLE_ERROR` | The check that you are not a robot could not be done just now. Please try again in a moment. |

The violation's cause is the `Verdict`: `$error->getCause()->getCause()->reasons`.

The constraint works without the form type, on a property of a DTO:

```php
use Omnishield\Bridge\Symfony\Validator\PassesChallenge;

final class ContactRequest
{
    #[PassesChallenge(gateway: 'forms', action: 'contact', hostname: true)]
    public string $captcha = '';
}
```

The visitor's address is the request's `getClientIp()`: behind a proxy, set
`framework.trusted_proxies`. A key the provider refuses is thrown (`InvalidKeyException`): it is
the site's error, not the visitor's.

## The ALTCHA challenge's route

The ALTCHA widget carries its challenge in the page by default. A page that is cached - by a
reverse proxy, by the browser - would serve everyone the same challenge: let the widget fetch a
fresh one instead.

```php
// config/routes/omnishield.php
use Omnishield\Bridge\Symfony\Controller\ChallengeController;
use Symfony\Component\Routing\Loader\Configurator\RoutingConfigurator;

return static fn (RoutingConfigurator $routes) => $routes->import(ChallengeController::class, 'attribute');
```

```yaml
omnishield:
    gateways:
        forms: { factory: altcha, options: { hmac_key: '%env(ALTCHA_HMAC_KEY)%', challenge_url: /omnishield/forms/challenge } }
```

`GET /omnishield/{gateway}/challenge?action=contact` (`omnishield_challenge`) answers a fresh
challenge, `Cache-Control: no-store`; 404 for a gateway that issues none. The controller is a plain
class: no `AbstractController`, nothing of FrameworkBundle.

## The widget's script, from the site

omnishield/altcha ships its widget's script (`public/altcha.min.js`, the npm package's file, MIT).
The bundle serves it at `/omnishield/altcha/3.3.0/altcha.min.js` - answered before routing, cached a
year, with no route to import and no asset pipeline (`ScriptListener`) - and makes that the default
`script` of every `altcha` gateway that names none: the page reaches nobody, `Widget::$origins` is
empty. `serve_scripts: false` leaves the package's default (jsDelivr); a `script` option given is
the application's.

## The widget's language

A gateway whose widget takes texts (`LocalizableInterface`: ALTCHA) is printed in the request's
language, by the form type and by `omnishield_widget()` alike (`WidgetLocalizer`): its texts come
from the translation domain `omnishield`, `<gateway>.<text>` - `altcha.label`, `altcha.verifying`,
`altcha.verified`... -, shipped in French, English, German and Japanese
(`Bridge/Symfony/translations/`). An application's own `translations/omnishield.<locale>.yaml`
overrides any text, or adds a language; a language nobody translated leaves the widget's own
English. A gateway's `language` option fixes its language whatever the visitor's, its `strings`
option wins over the catalogues. Without `symfony/translation`, nothing changes.

## Twig

For a form that is not a Symfony form:

```twig
<form method="post">
    {# ... #}
    {{ omnishield_widget() }}                          {# the default captcha #}
    {{ omnishield_widget('forms', 'contact') }}        {# a captcha, an action #}
    {{ omnishield_widget('forms', nonce: csp_nonce) }}
</form>

{% set widget = omnishield_widget_data('forms') %}    {# its parts: widget.field, widget.origins, widget.thirdParty #}
```

A provider's script is printed once per page, however many widgets and fields: with the first
printed. A field's `omnishield_html` is printed when the field is, so a form's view built and
thrown away (or a field taken out of the view) leaves the script to the one the page shows.

## Consent

Turnstile and reCAPTCHA reach a third party (`widget.reachesOthers()`), and reCAPTCHA sets a
cookie: their widget is loaded once the visitor agreed, and a form whose visitor refused needs
another captcha - ALTCHA, which reaches nobody. See [privacy](privacy.md).
