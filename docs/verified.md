---
title: Verified, and not
order: 90
---

## Verified, and not

### What the tests prove

Run on 2026-10-26 in the workspace (every package from its checkout, PHP 8.4, PHPUnit 11.5,
offline): 159 tests, 1231 assertions, green. Each of the six gateways runs the conformance suite
(`Omnishield\Testing\GatewayContractTests`, [Conformance suite](gateways.md#conformance-suite)) in
`Tests/<Name>ContractTest.php`, on recorded answers, and so does `Testing\FixedGateway`
(`Tests/FixedGatewayContractTest.php`):

- the factory and the registry, the required options;
- each question claimed by the `Capabilities` answered through its interface, and only those;
- nothing asked for nothing: an empty token `missing`, an empty identity unknown, without a request;
  a gateway that says it reaches nobody making no HTTP request at all;
- the models' invariants (a verdict passes exactly when it has no reason, the reasons the family's,
  a score in 0..1 where the gateway scores, a known reputation saying which parts matched);
- every failure of the provider (no answer, 5xx, 429, 401, 403, an unreadable answer) the right
  exception naming the provider.

The core's own tests cover the registry, the models, the HTTP answer, the replay stores, the Symfony
bundle (`Tests/Bridge/OmnishieldBundleTest.php`), the form field and its constraint
(`Tests/Bridge/ChallengeFormTest.php`), the widget's language (`Tests/Bridge/WidgetLocalizerTest.php`)
and the plain-PHP run with no framework class loaded (`Tests/BareTest.php`).

The examples of this manual were run in the workspace on 2026-10-26, offline, the providers'
answers given by a `MockHttpClient`, and their output pasted as printed: the fixed gateway, the
registry's errors, the bundle's configuration tree, and each provider's example. The installation
page's script is the exception: its output is the one of 2026-10-07, when Cloudflare and
StopForumSpam were called for real.

### Against the providers

Each provider's manual says what was verified for real. In short (from their manuals): Turnstile's
three testing keys and reCAPTCHA v2's test keys against `siteverify`, and StopForumSpam's lookups,
called for real on 2026-10-07; ALTCHA's whole way in PHP, and its script compared with npm's;
Akismet, reCAPTCHA v3 and Enterprise, and StopForumSpam's reports not, for want of a key; no widget
run in a browser.

### What is not verified

- A widget in a browser: the markup follows each provider's documentation and README.
- `CacheReplayStore` under concurrent requests (a PSR-6 pool has no atomic add).
- The [Laravel](laravel.md) page was written from omnibridge/laravel's code, not run in a Laravel
  application.

### Responsibility

`omniscient/omnishield` and its `omnishield/*` providers are independent, unofficial clients: none
is affiliated with, endorsed by or sponsored by the services it reaches. Each service keeps its own
terms (accounts and keys, rate limits, commercial or non-commercial use, what becomes of the
visitors' data), named with a link in the "Responsibility" section of the README of each provider
that reaches one. The MIT licence covers this code only and grants no right to any service or to
its data; whoever runs these packages complies with those terms.
