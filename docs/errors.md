---
title: Errors
order: 50
---

# Errors

A visitor who fails a captcha, a comment that is spam, an address that is listed: none of them is
an exception. They are answers - a `Verdict` that did not pass, a `Classification` labelled spam, a
known `Reputation`. Exceptions are for what decided nothing: a provider that did not answer, a key
it refused, a configuration to fix.

## The exceptions

Every exception Omnishield throws implements `Omnishield\Exception\OmnishieldException` (a
`\Throwable`):

| Exception | Extends | When |
|---|---|---|
| `UnreachableException` | `ProviderException` | the provider did not answer: no connection, a timeout, a server error (HTTP 5xx), a quota exceeded (HTTP 429), Turnstile's `internal-error`. Nothing was decided |
| `InvalidKeyException` | `ProviderException` | the provider refused the site's key or secret: HTTP 401 or 403 from a gateway that sends one, `missing-input-secret` / `invalid-input-secret`, Akismet's `invalid` about the key, Enterprise's `PERMISSION_DENIED`. The configuration is wrong, whatever the visitor did |
| `ProviderException` | `\RuntimeException` | the provider answered with an error rather than a verdict: `bad-request`, an answer that is not JSON, StopForumSpam's `success: 0`, an Akismet request it could not read. `$provider` names it (`turnstile`), `$providerCode` keeps its own code; the message starts with `[<provider>]` |
| `InvalidConfigException` | `\LogicException` | a gateway not configured, a factory not installed, a required option missing or empty, an option out of its range (a reCAPTCHA mode, a threshold, an ALTCHA algorithm or key prefix, StopForumSpam's `send` or `tor`), a list file unreadable, a submission without what the provider needs (Akismet: the visitor's IP and the site's home page; a StopForumSpam report: all three parts) |
| `NotSupportedException` | `\LogicException` | the gateway does not answer that question (`Registry::challenge()` of a list), or does not take reports (StopForumSpam without `api_key`) |

The HTTP statuses map the same way in every gateway, and the conformance suite checks it of each
one: no answer, 5xx and 429 an `UnreachableException`; 401 and 403 an `InvalidKeyException` (a
`ProviderException` for a gateway that sends no key: StopForumSpam's lookup); an answer it cannot
read a `ProviderException` - never a `TypeError` or a PHP warning.

These, as the core and a factory throw them (run in the workspace on 2026-10-26, offline):

```
NotSupportedException: The "emails" gateway (Disposable e-mail domains) does not answer "is this content spam?"; it answers: reputation.
InvalidConfigException: No "comments" gateway; configured: forms, strict, emails.
InvalidConfigException: No "akismet" factory for the "comments" gateway; installed: none.
InvalidConfigException: The "altcha" gateway needs: hmac_key.
```

Outside the family's exceptions: `DisposableList` throws a `\RuntimeException` when it cannot read
its file or write a refreshed one; the Symfony bridge's validator a `\LogicException` when no
captcha is named and `omnishield.challenge.gateway` is unset (as does `omnishield_widget()`), and
Symfony's `UnexpectedValueException` for a value that is not a string; `ChallengeController` a 404
for a gateway that issues no challenge.

## What to catch where

| Where | Catch |
|---|---|
| a form's handler, verifying a captcha | `UnreachableException`: refuse the form, or let it through and look later - the site's call (the bridge's `challenge.unreachable`). `InvalidKeyException` is the site's error: let it surface, alert someone |
| a comment classified | `UnreachableException`: hold the comment for moderation rather than publish it |
| a list asked at sign-up | `UnreachableException`: go on without its answer |
| building the registry, a deploy | `InvalidConfigException`: a configuration to fix, not to retry |
| a feature a gateway may lack | read `capabilities()` first; `NotSupportedException` is a programming error |

The bridge's `PassesChallenge` constraint does this for a captcha: a provider that does not answer
is the violation `UNREACHABLE_ERROR` (or nothing, with `unreachable: accept`), a refused key is
thrown ([Symfony](symfony.md#a-captcha-in-a-form)).
