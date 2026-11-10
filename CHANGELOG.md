# Changelog

## [Unreleased]

### Added

- omnisense/omnishield on omnisense/contracts, the kernel every omni family builds on, with usage
  policies. `Config`, `GatewayFactory` (`populate()` and `build()` as before), `Registry`,
  `Http\Redaction` and the exceptions keep their names and their API on top of the kernel's
  (prefix `omnishield`); `GatewayInterface` is a contracts provider, `Capabilities` gains
  `policy()` and `contracts()`.
- Usage policies: every gateway package declares its own (`policy.json`); `Registry::challenge()`,
  `classifier()` and `reputation()` give the gateway behind its guard when its policy limits one of
  the contract's operations (`Policy\Guarded*`); `policy`/`usage` per gateway (and in the bundle);
  `Registry::policy()`, `policies()`, `guard()`; a shared `RateLimiterInterface` as the registry's
  third argument; `Exception\UsageLimitReachedException`. See docs/policies.md.
- `Http\Answer::send()` takes the gateway's `Backoff`: a 429's `Retry-After` holds the next calls,
  still `UnreachableException`s (`retryAfter()`).
- composer.json requires `ext-mbstring` (`Answer` calls `mb_substr()`; the kernel requires it too).
- PHPStan at level max (`phpstan.neon`, no baseline) on the package and its tests: the types made
  real where they were mixed - `Answer::json()` an object's keys, `Capabilities`, `Verdict` and
  `Reputation::toArray()` field by field, `InMemoryReplayStore`'s clock a `Closure(): int`, the form
  field's options read as the resolver allows them, `WidgetLocalizer` asking a `LocaleAwareInterface`
  translator for its locale, `Testing\GatewayContractTests` asking each contract through its
  interface; `Testing\FixedGatewayFactory::create()` gives a `FixedGateway`. Nothing else changes.

### Fixed

- An `UnreachableException` no longer carries the request's secrets. `Answer::send()` put the HTTP
  client's message, the whole URL with a key in its query, in its message, wrapped the client's own
  exception as its previous, and every trace of the chain kept the arguments of the calls under it
  (the query, headers and body with the key, when `zend.exception_ignore_args` is off). The message
  and the previous - now a copy of the client's exception, the same class and code - have the key's
  parameters, the URL's user part and the request's secrets masked as `***` (`Http\Redaction`),
  and no trace of the chain keeps its arguments, a 5xx's or a 429's included.
