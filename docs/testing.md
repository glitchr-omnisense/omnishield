---
title: Testing
order: 60
---

# Testing

Testing an application that guards its forms with Omnishield. Writing a gateway and its
conformance suite: [Writing a gateway](gateways.md#writing-a-gateway).

## The fixed gateway

`Omnishield\Testing\FixedGateway` always says the same: it passes every token, every submission and
every identity, or refuses them all - and it answers the three questions, so one configuration
stands in for any gateway. Its factory is `fixed` (`Testing\FixedGatewayFactory`, option `pass`,
default true), always registered by the Symfony bundle and by omnibridge/laravel.

```yaml
# config/packages/test/omnishield.yaml, or when@test
omnishield:
    gateways:
        forms: { factory: fixed }                    # the site's captcha, in tests
        comments: { factory: fixed, options: { pass: false } }   # every comment spam
```

| | `pass: true` | `pass: false` |
|---|---|---|
| `widget($action)` | `<input type="hidden" name="omnishield-token" value="omnishield-fixed-token">` (`FixedGateway::FIELD`, `TOKEN`) | the same |
| `verify()` | passed (score 1.0); an empty token `missing` | refused `invalid` (score 0.0); an empty token `missing` |
| `classify()` | `Classification::ham()` | `Label::SPAM`, reason `fixed` |
| `report()` | kept in `$reports` (the submission, `$spam`) for the test to read | the same |
| `lookup()` | unknown | known, frequency 1, confidence 100, the parts given |

Its widget is a hidden field already holding a token: a form submitted by a test client (Symfony's
`KernelBrowser`, Laravel's HTTP tests) carries it as a browser would, and a test can still check that
a form posted without it is turned away. `FixedGateway::passing()` and `failing()` build one by hand.

```php
<?php

require 'vendor/autoload.php';

use Omnishield\Disposable\DisposableGatewayFactory;
use Omnishield\Model\Attempt;
use Omnishield\Model\Identity;
use Omnishield\Model\Submission;
use Omnishield\Registry;
use Omnishield\Testing\FixedGatewayFactory;

$registry = new Registry([new FixedGatewayFactory(), new DisposableGatewayFactory()], [
    'forms' => ['factory' => 'fixed'],                              // passes every token but an empty one
    'strict' => ['factory' => 'fixed', 'options' => ['pass' => 'false']],   // an environment variable's "false"
    'emails' => ['factory' => 'disposable'],
]);

$widget = $registry->challenge('forms')->widget('contact');
echo $widget->html(), "\n";
$post = [$widget->field => 'omnishield-fixed-token'];
echo 'forms: ', $registry->challenge('forms')->verify(Attempt::fromPost($post, $widget))->passed ? 'passed' : 'refused', "\n";
echo 'forms, nothing posted: ', implode(', ', $registry->challenge('forms')->verify(Attempt::fromPost([], $widget))->reasons), "\n";
echo 'strict: ', implode(', ', $registry->challenge('strict')->verify(Attempt::fromPost($post, $widget))->reasons), "\n";
echo 'strict, a comment: ', $registry->classifier('strict')->classify(new Submission('Hello'))->label->value, "\n";
echo 'strict, a visitor: ', implode(', ', $registry->reputation('strict')->lookup(new Identity('192.0.2.10', 'a@example.org'))->reasons), "\n";
echo 'names: ', implode(', ', $registry->names()), '; factories: ', implode(', ', $registry->factories()), "\n";
```

```
<input type="hidden" name="omnishield-token" value="omnishield-fixed-token" data-omnishield-action="contact">
forms: passed
forms, nothing posted: missing
strict: invalid
strict, a comment: spam
strict, a visitor: ip, email
names: forms, strict, emails; factories: fixed, disposable
```

(run in the workspace on 2026-10-26, offline; `pass` given as the string `"false"`, as an
environment variable would give it)

## A provider on recorded answers

To test the application's handling of a real provider's answers - and of its failures - give the
provider's factory a `MockHttpClient` (symfony/http-client): each gateway that calls a provider takes
its HTTP client in its factory's constructor.

```php
use Omnishield\Exception\UnreachableException;
use Omnishield\Model\Attempt;
use Omnishield\Turnstile\TurnstileGatewayFactory;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

$turnstile = (new TurnstileGatewayFactory(new MockHttpClient([
    new MockResponse('', ['http_code' => 503]),
    new MockResponse('', ['http_code' => 503]),     // the retry under the same idempotency key
])))->create(['site_key' => '1x00000000000000000000AA', 'secret' => '1x0000000000000000000000000000000AA']);

$this->expectException(UnreachableException::class);
$turnstile->verify(new Attempt('XXXX.DUMMY.TOKEN.XXXX'));
```

Each provider package keeps its recorded answers in `Tests/Fixtures/` (JSON bodies, with their
headers where they matter): a site's tests can read the same shapes. ALTCHA calls nothing - solve a
challenge with `altcha-org/altcha`'s own `solveChallenge()`, as the [installation](installation.md)
script does - and the disposable list reads a file (point `list` at a small file of the test's).

## Spent tokens

`InMemoryReplayStore` takes a clock (`fn () => $now`), so a test moves time rather than waiting;
`count()` says how many tokens it keeps. In a Symfony functional test the store is the service
`Omnishield\Replay\ReplayStoreInterface`.

## The conformance suite

`Omnishield\Testing\GatewayContractTests` is the family's contract as PHPUnit tests, run by every
gateway package and by `Tests/FixedGatewayContractTest.php` here: [Conformance
suite](gateways.md#conformance-suite) lists what it checks and its hooks.
