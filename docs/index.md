# omniscient/omnishield

Omnishield is one PHP contract for guarding a form, whoever answers. It asks three questions, and
a gateway answers the one that concerns it:

- **is this token valid?** - a captcha (`ChallengeInterface`): ALTCHA, Cloudflare Turnstile,
  Google reCAPTCHA;
- **is this content spam?** - a classifier (`ClassifierInterface`): Akismet;
- **are this address, this e-mail, this name known for abuse?** - a list (`ReputationInterface`):
  StopForumSpam, the disposable e-mail domains.

```php
$widget = $registry->challenge('forms')->widget('contact');      // what the page shows: script, element, field
echo $widget->html();                                             // inside the <form>

$verdict = $registry->challenge('forms')->verify(Attempt::fromPost($_POST, $widget, $ip));
$verdict->passed;                                                 // false: $verdict->reasons - missing, invalid, duplicate...

$registry->classifier('comments')->classify($submission)->isSpam();
$registry->reputation('emails')->lookup(new Identity($ip, $email))->known;
```

The design is Payum's, as in the family's other cores: a provider's **factory** fills a `Config`
with its name, its options and their defaults and builds a **gateway**; a **registry** holds the
site's gateways by name. The core needs no framework: it requires PHP 8.2 and
`symfony/http-client-contracts` (the interfaces, no client). A gateway that calls a provider
requires `symfony/http-client`, a library that stands alone. A Symfony bridge is included (a
bundle, a form field, a constraint, a route, a Twig function: [Symfony](symfony.md)); omnibridge/laravel
wires the registry under Laravel ([Laravel](laravel.md)).

A gateway answers. It does not decide what a refusal means for the form, nor what to do when its
provider does not answer: the application does. **A refusal is a `Verdict`, a `Classification`, a
`Reputation` - never an exception**; a provider that did not answer is an `UnreachableException`,
a key it refused an `InvalidKeyException`, and neither is ever taken for "fine". It is told strings
- a token, an address, a text, a name - never an account, an entity or a request. What a gateway
cannot do is a `NotSupportedException`, and its `Capabilities` say so beforehand.

Published as `omniscient/omnishield`, formerly `glitchr/omnishield` (and before 2026-10-10
`glitchr/omniguard`): the package `replace`s `glitchr/omnishield`, so a `composer.json` that still
requires the old name resolves to this package. The namespace is `Omnishield\`; the providers keep
the `omnishield/` vendor and the `Omnishield\<Provider>\` namespace.

### When to use it

- A contact form, a comment form, a sign-up, a sign-in after failed tries: any form a bot should
  not post, with the captcha chosen per site (or per consent: ALTCHA when the visitor refused
  third parties, [Privacy](privacy.md)).
- Comments to moderate: a classifier holds the spam and drops the flagrant, and learns from the
  moderator's corrections (`report()`).
- A sign-up: the e-mail's domain against the disposable list, the address against StopForumSpam.

It is not a firewall (StopForumSpam's terms forbid checking every visitor: ask about those who
submit a form), and it keeps nothing of the visitors but the spent ALTCHA tokens.

### Providers

Six packages. The columns are what each gateway's `capabilities()` says (read from the code), and
what it calls.

| Package | Factory | Answers | Calls | Third party | Cookies | Score | Action checked | Reports | Reads | Needs |
|---|---|---|---|---|---|---|---|---|---|---|
| [`omnishield/altcha`](https://github.com/glitchr-studio/omnishield-altcha) | `altcha` | challenge (it issues its challenges: `ChallengeIssuerInterface`; texts: `LocalizableInterface`) | nothing | no | no | no | yes, signed in the challenge | - | - | `hmac_key` |
| [`omnishield/turnstile`](https://github.com/glitchr-studio/omnishield-turnstile) | `turnstile` | challenge | Cloudflare's `siteverify` | yes | no | no | yes | - | - | `site_key`, `secret` |
| [`omnishield/recaptcha`](https://github.com/glitchr-studio/omnishield-recaptcha) | `recaptcha` | challenge | Google's `siteverify`; Enterprise `assessments` | yes | yes (`_GRECAPTCHA`) | `score`, `enterprise` modes | `score`, `enterprise` modes | - | - | `site_key`, `secret`; Enterprise `site_key`, `project_id`, `api_key` |
| [`omnishield/akismet`](https://github.com/glitchr-studio/omnishield-akismet) | `akismet` | classifier | `comment-check`, `submit-spam`, `submit-ham`, `verify-key` | yes | no | - | - | yes | - | `api_key` |
| [`omnishield/stopforumspam`](https://github.com/glitchr-studio/omnishield-stopforumspam) | `stopforumspam` | reputation | `api`; `add` to report | yes | no | - | - | with `api_key` (its own `report()`) | `send`: ip, email, name | nothing; `api_key` to report |
| [`omnishield/disposable`](https://github.com/glitchr-studio/omnishield-disposable) | `disposable` | reputation | nothing (a list shipped in the package) | no | no | - | - | - | email | nothing |
| `omniscient/omnishield` | `fixed` | all three, for tests (`Testing\FixedGateway`) | nothing | no | no | yes (1.0 or 0.0) | yes | yes | ip, email, name | nothing (`pass`) |

Each provider's own manual lists its options, its answers and what was verified against the
provider for real.

### How this manual is organised

- [Installation](installation.md): requirements, a whole script in plain PHP, a shared store of
  spent tokens.
- [Symfony](symfony.md): the bundle's configuration tree, its services, the form field, the
  constraint, the route, the served script, the widget's language, Twig; [Laravel](laravel.md).
- [Models](models.md): `Widget`, `Attempt`, `Verdict`, `Submission`, `Classification`, `Label`,
  `Identity`, `Reputation`, `Capabilities`, the printer.
- [The three questions](gateways.md): the contracts, a captcha, a classifier, a list, writing a
  gateway and its conformance suite.
- [Privacy](privacy.md): who sees what, cookies, consent.
- [Configuration reference](configuration.md): the registry's configuration, every provider's
  options, the reserved keys.
- [Errors](errors.md), [Testing](testing.md), [the Docker harness](harness.md),
  [Verified, and not](verified.md).
