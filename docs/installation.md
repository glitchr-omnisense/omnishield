---
title: Installation
order: 10
---

# Installation

```sh
composer require omniscient/omnishield omnishield/altcha     # a captcha the site issues and checks itself: no key, no third party
composer require omnishield/disposable                      # disposable e-mail domains: a list, no call
composer require omnishield/turnstile omnishield/recaptcha   # Cloudflare's, Google's captcha
composer require omnishield/akismet omnishield/stopforumspam # a spam classifier, a list of reported abusers
```

## Requirements

- PHP 8.2 or later.
- `omniscient/omnishield` requires `symfony/http-client-contracts` (^3.0) and nothing else.
- `omnishield/turnstile`, `omnishield/recaptcha`, `omnishield/akismet` and `omnishield/stopforumspam`
  require `symfony/http-client` (6.4, 7 or 8); `omnishield/altcha` requires `altcha-org/altcha`
  (^2.3) and no HTTP client; `omnishield/disposable` requires nothing more.
- Suggested, for what uses them: `psr/cache` (`CacheReplayStore`), `phpunit/phpunit` and
  `symfony/http-client` (the conformance suite), and for the Symfony bridge `symfony/config`,
  `symfony/dependency-injection`, `symfony/http-kernel`, `symfony/form`, `symfony/validator`,
  `symfony/routing`, `symfony/translation`, `twig/twig`.

Omnishield needs no framework. The core requires PHP and `symfony/http-client-contracts` - the
HTTP client's interfaces, no client; a gateway that calls a provider requires
`symfony/http-client` (a library, not a framework) and takes the application's client when it is
given one. It runs the same in plain PHP, in a worker, in Laravel or Slim, and in Symfony, where a
bundle does the wiring ([Symfony](symfony.md)).

## Plain PHP

```php
<?php // guard.php

require __DIR__.'/vendor/autoload.php';

use Omnishield\Altcha\AltchaGatewayFactory;
use Omnishield\Disposable\DisposableGatewayFactory;
use Omnishield\Model\Attempt;
use Omnishield\Model\Identity;
use Omnishield\Registry;
use Omnishield\Replay\InMemoryReplayStore;
use Omnishield\Stopforumspam\StopforumspamGatewayFactory;
use Omnishield\Turnstile\TurnstileGatewayFactory;

$registry = new Registry([new AltchaGatewayFactory(new InMemoryReplayStore()), new TurnstileGatewayFactory(), new StopforumspamGatewayFactory(), new DisposableGatewayFactory()], [
    'forms' => ['factory' => 'altcha', 'options' => ['hmac_key' => 'a-long-random-secret-of-the-site']],
    'cloudflare' => ['factory' => 'turnstile', 'options' => ['site_key' => '1x00000000000000000000AA', 'secret' => '1x0000000000000000000000000000000AA']],
    'reported' => ['factory' => 'stopforumspam'],
    'throwaway' => ['factory' => 'disposable'],
]);

// The page: a widget inside the form.
$widget = $registry->challenge('forms')->widget('contact');
echo $widget->tag, ' posts "', $widget->field, '"; through a third party: ', $widget->thirdParty ? 'yes' : 'no', "\n";

// The browser's part, done here: the ALTCHA library solves the challenge the widget carries.
$challenge = AltchaOrg\Altcha\Challenge::fromArray(json_decode($widget->attributes['challenge'], true));
$solution = (new AltchaOrg\Altcha\Altcha())->solveChallenge(new AltchaOrg\Altcha\SolveChallengeOptions(challenge: $challenge, algorithm: AltchaGatewayFactory::algorithm('PBKDF2/SHA-256')));
$_POST = ['message' => 'Hello', 'altcha' => (new AltchaOrg\Altcha\Payload($challenge, $solution))->toBase64()];

// The form's handler.
foreach (['first post', 'the same again'] as $when) {
    $verdict = $registry->challenge('forms')->verify(Attempt::fromPost($_POST, $widget, '192.0.2.10'));
    printf("%s: %s\n", $when, $verdict->passed ? 'passed' : 'refused ('.implode(', ', $verdict->reasons).')');
}
$verdict = $registry->challenge('cloudflare')->verify(new Attempt('XXXX.DUMMY.TOKEN.XXXX', '192.0.2.10'));
printf("Turnstile, Cloudflare's testing keys: %s, from %s\n", $verdict->passed ? 'passed' : 'refused', $verdict->hostname);

foreach (['185.220.101.1' => null, '91.186.18.61' => 'someone@mailinator.com'] as $ip => $email) {
    $visitor = new Identity($ip, $email);
    foreach (['reported', 'throwaway'] as $list) {
        $reputation = $registry->reputation($list)->lookup($visitor);
        printf("%-9s %-14s %-24s %s\n", $list, $ip, $email ?? '-', $reputation->known ? 'known: '.implode(', ', $reputation->reasons).' (confidence '.$reputation->confidence.')' : 'unknown');
    }
}
```

```
$ php guard.php
altcha-widget posts "altcha"; through a third party: no
first post: passed
the same again: refused (duplicate)
Turnstile, Cloudflare's testing keys: passed, from example.com
reported  185.220.101.1  -                        known: ip, tor (confidence 95.29)
throwaway 185.220.101.1  -                        unknown
reported  91.186.18.61   someone@mailinator.com   unknown
throwaway 91.186.18.61   someone@mailinator.com   known: email, disposable (confidence 100)
```

(as answered on 2026-10-07, Cloudflare and StopForumSpam called for real)

That is all there is to it:

- a **factory** per gateway package, which takes what its gateway works with: the store of spent
  tokens for ALTCHA, an HTTP client for those that call a provider (one is created when none is
  given);
- the **registry**, built by hand from the factories and the gateways' options, by name;
- the **gateways** it gives, each answering its question: `challenge()`, `classifier()`,
  `reputation()` give a gateway as the contract that asks it ([gateways](gateways.md)).

No class of a framework is loaded on the way - a test of this package checks it in a process of
its own (`Tests/BareTest.php`), and so does `docker compose run --rm omnishield bare`
([harness](harness.md)).

## What the application does around a gateway

1. **Show** the captcha's widget in the form - `$widget->html()`, or its parts in your own markup.
   When it reaches a third party (`$widget->reachesOthers()`: Turnstile, reCAPTCHA), name it and
   wait for the visitor's consent first ([privacy](privacy.md)).
2. **Verify** the token on submission - `Attempt::fromPost($_POST, $widget, $ip)` reads it under
   the widget's field - and refuse the form when the verdict did not pass.
3. **Ask the lists** about the visitor (`Identity`: address, e-mail, name) and **classify** what
   they wrote (`Submission`), when it is worth it: a comment, a sign-up.
4. **Decide** what a provider that does not answer means for this form (`UnreachableException`):
   refuse it, or let it through and look later. Omnishield never decides it for you.

The address is the visitor's as your application sees it: behind a proxy or a load balancer, the
one it forwarded (Symfony: `trusted_proxies`), not the proxy's.

## A shared store of spent tokens

ALTCHA cannot tell a solution it saw before: the gateway remembers each one in a
`ReplayStoreInterface` until it would have lapsed. `InMemoryReplayStore` remembers within one
process - right for a test, a worker, the script above; behind PHP-FPM every request starts with
an empty one, and a solution could be posted twice. Share one:

```php
use Omnishield\Replay\CacheReplayStore;
use Symfony\Component\Cache\Adapter\RedisAdapter;   // any PSR-6 pool: filesystem, Redis, Memcached, APCu

new AltchaGatewayFactory(new CacheReplayStore(new RedisAdapter(RedisAdapter::createConnection('redis://localhost'))));
```

A PSR-6 pool has no atomic "add if absent": two requests posting the same solution within the
same microseconds could both pass. Where that matters, implement `ReplayStoreInterface::spend()` on
something atomic - a table with a unique key, Redis's `SET NX`.

## In a framework

- **Symfony**: `Omnishield\Bridge\Symfony\OmnishieldBundle` registers the factories, builds the
  registry from `config/packages/omnishield.yaml`, makes each gateway injectable by its name, and
  gives a form field, a constraint, a route and a Twig function: see [Symfony](symfony.md).
- **Laravel**: omnibridge/laravel's `Shield\ShieldServiceProvider` builds the registry from
  `config/omnishield.php` (the same keys) with the gateways that call no provider: see
  [Laravel](laravel.md).
- **Any other**: build the `Registry` once, where the framework builds its services, as the script
  above does; give it the framework's HTTP client and a shared store.

## Errors

A provider that does not answer, a key it refuses, an option missing: [Errors](errors.md) lists
every exception and when each is thrown.
