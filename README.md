# omniscient/omnishield

One contract for guarding a form, whoever answers. Three questions, and a gateway answers the one
that concerns it:

Published as `omniscient/omnishield` (formerly `glitchr/omnishield`, which this package replaces: a
`composer.json` that still requires the old name keeps resolving to this package, never both).
`omniscient/` is the vendor of every family's core; the providers keep theirs (`omnishield/turnstile`...).

- **is this token valid?** - a captcha: ALTCHA, Cloudflare Turnstile, Google reCAPTCHA;
- **is this content spam?** - a classifier: Akismet;
- **are this address, this e-mail, this name known for abuse?** - a list: StopForumSpam, the
  disposable e-mail domains.

```php
$widget = $registry->challenge('forms')->widget('contact');      // what the page shows: script, element, field
echo $widget->html();                                             // inside the <form>

$verdict = $registry->challenge('forms')->verify(Attempt::fromPost($_POST, $widget, $ip));
$verdict->passed;                                                 // false: $verdict->reasons - missing, invalid, duplicate...

$registry->classifier('comments')->classify($submission)->isSpam();
$registry->reputation('emails')->lookup(new Identity($ip, $email))->known;
```

This package holds the contract (`ChallengeInterface`, `ClassifierInterface`,
`ReputationInterface`, `GatewayFactory`, `Registry`), the models (`Widget`, `Attempt`, `Verdict`,
`Submission`, `Classification`, `Identity`, `Reputation`, `Capabilities`), the store of spent
tokens (`ReplayStoreInterface`), `Testing\FixedGateway` for an application's tests, and a bridge
for Symfony. It needs no framework: it requires PHP and `symfony/http-client-contracts` (the
interfaces, no client). Each gateway is a package of its own:

| Package | It answers | Third party | Cookies |
|---|---|---|---|
| [`omnishield/altcha`](https://github.com/glitchr-studio/omnishield-altcha) | is this token valid: a proof of work the site issues and checks itself | none (the widget's script: yours, or jsDelivr's) | none |
| [`omnishield/turnstile`](https://github.com/glitchr-studio/omnishield-turnstile) | is this token valid: Cloudflare's widget, Cloudflare's siteverify | Cloudflare | none |
| [`omnishield/recaptcha`](https://github.com/glitchr-studio/omnishield-recaptcha) | is this token valid: v2 checkbox and invisible, v3 score, Enterprise | Google | `_GRECAPTCHA` |
| [`omnishield/akismet`](https://github.com/glitchr-studio/omnishield-akismet) | is this content spam: ham, spam, flagrant; reports back | Akismet (Automattic) | - |
| [`omnishield/stopforumspam`](https://github.com/glitchr-studio/omnishield-stopforumspam) | is this identity known: the public database, a threshold of the site's | StopForumSpam | - |
| [`omnishield/disposable`](https://github.com/glitchr-studio/omnishield-disposable) | is this e-mail's domain disposable: a free list shipped in the package | none | - |

Formerly `glitchr/omniguard`, renamed on 2026-10-10: on Packagist the vendor `omniguard` belongs to
another project, so the family's providers could not be published under it. The namespace is
`Omnishield\`, the bundle `OmnishieldBundle`, its configuration `omnishield:`.

## What a gateway is, and is not

A gateway answers. It does not decide what a refusal means for the form, nor what to do when its
provider does not answer: the application does. **A refusal is a `Verdict`, a `Classification`, a
`Reputation` - never an exception**; a provider that did not answer is an
`UnreachableException`, a key it refused an `InvalidKeyException`, and neither is ever taken
for "fine".

It is told strings - a token, an address, a text, a name - and never an account, an entity or a
request.

What a gateway cannot do is a `NotSupportedException`, and its `Capabilities` say so beforehand:
a captcha classifies nothing, a list without a key takes no report.

## Documentation

- [Installation and a first form](docs/installation.md)
- [Models](docs/models.md)
- [The three questions: the contracts, the gateways, writing one](docs/gateways.md)
- [Symfony: the bundle, the form field, the constraint, the route, Twig](docs/symfony.md)
- [Privacy: who sees what, cookies, consent](docs/privacy.md)
- [The Docker harness: the console, bare PHP](docs/harness.md)

## Plain PHP

```sh
composer require omniscient/omnishield omnishield/altcha omnishield/disposable
```

```php
use Omnishield\Altcha\AltchaGatewayFactory;
use Omnishield\Disposable\DisposableGatewayFactory;
use Omnishield\Registry;

$registry = new Registry([new AltchaGatewayFactory($spentTokens), new DisposableGatewayFactory()], [
    'forms' => ['factory' => 'altcha', 'options' => ['hmac_key' => getenv('ALTCHA_HMAC_KEY')]],
    'emails' => ['factory' => 'disposable'],
]);
```

No bundle, no container: a factory per gateway package, the registry built by hand.
[docs/installation.md](docs/installation.md) opens on a whole script that runs as it is.

## Symfony

`Omnishield\Bridge\Symfony\OmnishieldBundle` does that wiring in a Symfony application, and adds a
form field, a constraint, the route of the ALTCHA challenge and a Twig function
([docs/symfony.md](docs/symfony.md)); none of their components is required by this package.

```yaml
omnishield:
    gateways:
        forms: { factory: altcha, options: { hmac_key: '%env(ALTCHA_HMAC_KEY)%' } }
        emails: { factory: disposable }
```

```php
$builder->add('captcha', ChallengeType::class, ['action' => 'contact']);
```

## Docker: every gateway, bare PHP

```sh
cd docker && cp .env.dist .env
docker compose run --rm omnishield gateways
docker compose run --rm omnishield bare          # plain PHP: no bundle, no container, and what PHP loaded
docker compose run --rm omnishield bare --live   # the same against the providers, with their testing keys
docker compose run --rm omnishield test
```

## Verified, and not

| | |
|---|---|
| The conformance suite (`Omnishield\Testing\GatewayContractTests`, [docs/gateways.md](docs/gateways.md#conformance-suite)) | **green on 2026-10-25** for all six gateways and `Testing\FixedGateway`, offline, in the workspace (`~/Sites/omnishield`, 159 tests): on recorded answers - each gateway's own "Verified, and not" says which were recorded from the provider and which written from its documentation |
| The gateways against their providers | see each gateway's documentation: Turnstile's and reCAPTCHA v2's testing keys and StopForumSpam's lookups for real on 2026-10-07; Akismet, reCAPTCHA v3 and Enterprise not, for want of a key |

## Responsibility

`omniscient/omnishield` and its `omnishield/*` providers are independent, unofficial clients: none is
affiliated with, endorsed by or sponsored by the services it reaches. Each service keeps its own
terms (accounts and keys, rate limits, commercial or non-commercial use, what becomes of the
visitors' data), named with a link in the "Responsibility" section of the README of each provider
that reaches one. The MIT licence covers this code only and grants no right to any service or to its
data.

Whoever installs and runs these packages is responsible for complying with those terms, for
obtaining whatever they require, and for any use made of what they return. The authors publish them
free of charge, make no commercial use of them, and accept no liability for how they are used (see
[LICENSE](LICENSE)).

License: MIT since 2026-10-09; earlier versions remain published under LGPL-3.0-or-later.
