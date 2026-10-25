---
title: Configuration reference
order: 40
---

# Configuration reference

## The registry's configuration

`new Registry($factories, $config)`, the bundle's `omnishield.gateways` ([Symfony](symfony.md#the-configuration-tree))
and `config/omnishield.php`'s `gateways` ([Laravel](laravel.md)) take the same shape: gateway name
=> factory and options.

```php
[
    '<gateway name>' => [
        'factory' => '<factory name>',     // required: a factory's getName()
        'options' => [/* ... */],          // optional, default []: handed to the factory's create()
    ],
]
```

The gateway name is the site's (`forms`, `comments`, `emails`); the factory name the package's
(`altcha`, `akismet`, `disposable`). Several gateways may use the same factory with different
options (two Akismet sites, a strict and a lenient StopForumSpam).

| `Registry` method | |
|---|---|
| `get($name)` | the gateway, built once from its factory and options |
| `challenge($name)`, `classifier($name)`, `reputation($name)` | the gateway as that contract, or a `NotSupportedException` naming what it answers |
| `has($name)`, `names()`, `options($name)`, `all()` | the configured gateways |
| `factories()` | the factories installed: what `factory` may name |
| `create($name, $overrides)` | a gateway built afresh, its options with `$overrides` over them (a key typed in a back office); not kept |

An unknown gateway, or a factory not installed, is an `InvalidConfigException` naming what is
configured or installed.

## How options are read

A factory fills a `Config` (an `ArrayObject`): the options given, its defaults under them
(`defaults()` sets a key only when it is not set), and its required keys, checked by
`validateNotEmpty()` - missing, `''` or `[]` is an `InvalidConfigException`: `The "altcha" gateway
needs: hmac_key.` The helpers the factories read options with, so that an environment variable
works as well as a typed value:

| Helper | Reads |
|---|---|
| `get($key, $default)` | the value as it is |
| `string($key)` | a string; null when empty |
| `bool($key)` | a bool, or `"1"`, `"true"`, `"yes"`, `"on"` (any case) as true |
| `list($key)` | a list, or one comma-separated string (`ip,email`), trimmed, empty items dropped |

Reserved keys start with `omnishield.`: `omnishield.factory_name`, `omnishield.factory_title`,
`omnishield.required_options`. Given in the options they win over the factory's (a required
option made optional, say) - a way to adapt one gateway without writing a factory.

## Every provider's options

Each provider's manual explains its options; the table lists them as each factory declares them,
with their defaults.

| Factory | Required | Optional (default) |
|---|---|---|
| `altcha` | `hmac_key` | `algorithm` (`PBKDF2/SHA-256`), `cost` (5000), `key_prefix` (`00`), `expires` (600), `challenge_url` (null), `field` (`altcha`), `script` (jsDelivr's `altcha@3.3.0`; under the bridges the site's copy), `integrity` (null: set for the two known addresses), `attributes` ([]), `configuration` ([]), `language` (null), `strings` ([]) |
| `turnstile` | `site_key`, `secret` | `theme`, `size`, `appearance`, `language` (null), `attributes` ([]), `field` (`cf-turnstile-response`), `idempotency` (true), `base_uri` (`https://challenges.cloudflare.com/turnstile/v0/`) |
| `recaptcha` | `site_key`; `secret` (checkbox, invisible, score) or `project_id` and `api_key` (enterprise) | `mode` (`checkbox`), `threshold` (0.5), `default_action` (`submit`), `domain` (`www.google.com`), `language`, `theme`, `size`, `badge` (null), `attributes` ([]) |
| `akismet` | `api_key` | `site` (null), `language` (null), `test` (false), `base_uri` (`https://rest.akismet.com/1.1/`) |
| `stopforumspam` | - | `threshold` (50), `send` (`[ip, email, name]`), `hash_email` (true), `expire` (null), `tor` (`neutral`), `wildcards` (true), `api_key` (null), `base_uri` (`https://api.stopforumspam.org/api`), `report_uri` (`https://www.stopforumspam.com/add`) |
| `disposable` | - | `list` (null: the shipped file), `allow` ([]), `deny` ([]) |
| `fixed` | - | `pass` (true) |

A factory's constructor takes what its gateway works with, not options: an HTTP client for those
that call a provider (`?HttpClientInterface $http`, `HttpClient::create()` when none is given), the
store of spent tokens for ALTCHA (`?ReplayStoreInterface $replays`, `InMemoryReplayStore` when none
is given).

## The store of spent tokens

| Class | Constructor | |
|---|---|---|
| `Replay\InMemoryReplayStore` | `(?\Closure $clock = null)`: a closure giving the time, `time()` otherwise | this process's memory; `count()` the tokens kept |
| `Replay\CacheReplayStore` | `(CacheItemPoolInterface $pool, string $prefix = 'omnishield.spent.')` | a PSR-6 pool, each token's id hashed (SHA-256) under the prefix, kept until it would have lapsed |

`ReplayStoreInterface::spend($id, $until)` is true the first time, false after; an implementation
of the site's on an atomic store (a unique key, Redis's `SET NX`) is exact under concurrent
requests, where a PSR-6 pool (read, then written) is not.
