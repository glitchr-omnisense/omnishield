---
title: Usage policies
order: 45
---

# Usage policies

Every omnishield gateway carries a usage policy: omnisense/contracts' `UsagePolicy`
([its schema and rules](https://github.com/glitchr-omnisense/contracts/blob/1.x/docs/policies.md)),
given by its `Capabilities` (`capabilities()->policy()`). Each gateway package declares its own in
a `policy.json` beside its classes (named in its `composer.json`, `extra.omnisense.policy`), read
at its sources on the day its `verifiedAt` says. What the terms do not say is declared unknown
(`rateLimits: null`, a right `unknown`), never guessed; where the schema cannot say a rule, the
stricter form it can say is declared and the rule itself is in `provenance.notes`.

| Gateway | Rate limits (as declared) | Rights, in short |
|---|---|---|
| omnishield/altcha | none: the site issues and verifies its challenges, nobody is called | MIT (the script and the library): their notice kept in the copies |
| omnishield/turnstile | none ("Unlimited challenges" on the Free plan) | commercial use allowed; Cloudflare's branding stays on the widget |
| omnishield/recaptcha | `verify`: 1,000 a second and 1,000,000 a month per key (the FAQ's quota for classic keys) | commercial use allowed; "This site is protected by reCAPTCHA." shown when the badge is hidden; the 10,000 free assessments a month are a price, in the notes |
| omnishield/akismet | unknown: each plan's monthly allowance, throttled above at Automattic's discretion | commercial sites need a paid plan (`contract`); free keys for personal sites only |
| omnishield/stopforumspam | `lookup`: 20,000 a day (the legal page; 100,000 on the usage page read in October: the stricter declared) | non-commercial, a commercial system for its internal use only (`contract`); no resale of the data (redistribution forbidden); never as a firewall |
| omnishield/disposable | none: the list ships in the package | CC0 1.0 |

## Where calls are checked

`Registry::challenge()`, `classifier()` and `reputation()` give the gateway behind its guard
(`Omnishield\Policy\GuardedChallenge`, `GuardedChallengeIssuer`, `GuardedLocalizableChallenge`,
`GuardedLocalizableIssuer`, `GuardedClassifier`, `GuardedReputation`: each implements exactly what
the gateway does, so `instanceof ChallengeIssuerInterface` or `LocalizableInterface` still holds)
when its policy, as the site runs it, limits an operation of the contract: `verify`, `classify`,
`report`, `lookup` (`widget()` and `issue()` call nobody and are not counted). Otherwise, and from
`get()`, the gateway itself. `inner()` gives a guarded gateway's own methods back (Akismet's
`verifyKey()`, StopForumSpam's `report()`).

A call over a limit is never made: `UsageLimitReachedException` (an `OmnishieldException`), with
`retryAfter` in seconds and the `limit` reached. The constraint `PassesChallenge` treats it as a
provider that did not answer (`challenge.unreachable`).

A provider's own 429 stays an `UnreachableException`, as it always was, and its `Retry-After` (or
a `RateLimit` field with nothing left) is now kept: every next call of that gateway is refused
without a request until the wait is over, an `UnreachableException` too. Its previous is the
`UsageLimitReachedException`, and `UnreachableException::retryAfter()` gives the seconds.

## A site's tightening, and what it says of itself

```php
$registry = new Registry($factories, [
    'forms' => ['factory' => 'recaptcha', 'options' => [...], 'policy' => ['rateLimits' => [['max' => 10000, 'window' => 'month', 'operations' => ['verify']]]]],
    'abusers' => ['factory' => 'stopforumspam', 'usage' => ['commercial' => true, 'contract' => true]],
], $limiter);
```

`policy` (beside `options` or in it) adds the site's limits and narrows its rights; anything looser
than the declaration is an `InvalidPolicyException`. `usage` says what the site is: a commercial
site on a gateway whose commercial use needs a contract (Akismet, StopForumSpam) must say
`contract: true`, else `UsageNotPermitted` the first time the gateway is asked. In the Symfony bundle
both are keys of a gateway beside `factory` and `options`.

`$registry->policy('forms')` gives the policy as the site runs it, `policies()` every one, and the
third argument of the registry a shared `RateLimiterInterface` (omnibridge/symfony's over the
Symfony rate limiter, once there) when the application runs in many processes; an in-memory one by
default.
