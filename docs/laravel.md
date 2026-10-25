---
title: Laravel
order: 16
---

## Laravel

omnibridge/laravel, the bridge that hosts omnibase in Laravel, has an Omnishield provider:
`Omnibridge\Laravel\Shield\ShieldServiceProvider`. `OmnibridgeServiceProvider` registers it when
the class `Omnishield\Registry` exists; it reads `config/omnishield.php`, which takes the keys of
the Symfony bundle's `omnishield.yaml`, and wires nothing at boot without a gateway. It was written
for omnibase's captcha after failed sign-ins (omniauth's step AU8b), and does less than the bundle.
This page describes omnibridge/laravel at its 1.x branch.

```php
// config/omnishield.php (php artisan vendor:publish --tag=omnishield-config)
return [
    'gateways' => [
        'forms' => ['factory' => 'altcha', 'options' => ['hmac_key' => env('ALTCHA_HMAC_KEY')]],
        'emails' => ['factory' => 'disposable'],
    ],
    'challenge' => ['gateway' => null, 'unreachable' => 'accept'],
    'serve_scripts' => true,
    'replay' => ['pool' => null],
];
```

| Key | Default | |
|---|---|---|
| `gateways.<name>.factory`, `.options` | `[]` | as under Symfony; an entry without a string `factory` is skipped |
| `challenge.gateway` | `null`: the first gateway | `ShieldServiceProvider::defaultChallenge($app)` |
| `challenge.unreachable` | `accept` | `ShieldServiceProvider::acceptsUnreachable($app)`: anything but `reject` accepts |
| `serve_scripts` | `true` | ALTCHA's script served at `/omnishield/altcha/3.3.0/altcha.min.js` (route `omnishield_altcha_script`, cached a year) and made the default `script` of every `altcha` gateway that names none |
| `replay.pool` | `null` | a binding that is a PSR-6 pool: `CacheReplayStore` on it; otherwise `InMemoryReplayStore` |

> [!WARNING]
> Known defect: `challenge.unreachable` defaults to `accept` under Laravel, where the Symfony
> bundle's same key defaults to `reject` - the same configuration lets a form through under one
> host and refuses it under the other when a provider does not answer. With the factories Laravel
> registers today (below) no gateway calls a provider, so none is ever unreachable.

### What it binds

| Binding | |
|---|---|
| `Omnishield\Registry` | the gateways of `config/omnishield.php`, built by `Omnishield\Altcha\AltchaGatewayFactory` (given the replay store), `Omnishield\Disposable\DisposableGatewayFactory` and `Omnishield\Testing\FixedGatewayFactory`, those installed |
| `Omnishield\Replay\ReplayStoreInterface` | `CacheReplayStore` on `replay.pool`, else `InMemoryReplayStore` |
| `Omnishield\WidgetPrinter` | one printer, a singleton |

Only the factories that need no HTTP client are registered: a gateway configured with `turnstile`,
`recaptcha`, `akismet` or `stopforumspam` is an `InvalidConfigException` (`No "turnstile" factory
for the "forms" gateway; installed: altcha, disposable, fixed.`) the first time it is asked for. A
site that needs one builds its factory itself, with a `symfony/http-client` client, and binds its
own `Registry`:

```php
// app/Providers/ShieldServiceProvider.php
use Omnishield\Registry;
use Omnishield\Turnstile\TurnstileGatewayFactory;
use Symfony\Component\HttpClient\HttpClient;

$this->app->singleton(Registry::class, fn () => new Registry([
    new TurnstileGatewayFactory(HttpClient::create()),
], config('omnishield.gateways')));
```

> [!WARNING]
> Known defect: the `WidgetPrinter` is a plain singleton, never reset, where the Symfony bundle
> resets it between two requests (`kernel.reset`): under a long-running worker (Octane,
> FrankenPHP) a provider's script printed on one page would be left out of the widgets of the next
> pages.

There is no form field, constraint, challenge route or Twig function under Laravel: a form prints
`$widget->html()` (or `WidgetPrinter::print()`) and verifies with
`$registry->challenge($name)->verify(Attempt::fromPost($request->all(), $widget, $request->ip()))`,
as in plain PHP ([Installation](installation.md)). With omnibase's stores and omnibase 4.x,
omnibridge/laravel's `Auth\OmnibaseEffects` builds omnibase's `FormGuard` and `SignInCaptcha` on
this registry, with `defaultChallenge()` and `acceptsUnreachable()` ([omniauth's Laravel
page](https://github.com/glitchr-studio/omniauth/blob/1.x/docs/laravel.md)).

This page was written from omnibridge/laravel's code; it was not run in a Laravel application here.
