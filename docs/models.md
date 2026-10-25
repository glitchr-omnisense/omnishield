---
title: Models
order: 20
---

# Models

All models are `readonly`; times are `DateTimeImmutable`. They carry strings and dates - never an
account, an entity or a request: the application fills them from its own.

## Widget

What the page shows for a captcha (`ChallengeInterface::widget($action)`).

| Property | |
|---|---|
| `field` | the posted field that carries the token: `altcha`, `cf-turnstile-response`, `g-recaptcha-response` |
| `script`, `scriptAttributes` | the provider's script and its element's attributes (`type`, `async`, `defer`, `integrity`, `crossorigin`) |
| `tag`, `attributes`, `content` | the element the script turns into a widget: `altcha-widget`, `div`; `true` prints an attribute's name alone, `false` leaves it out |
| `inline` | a short script of the gateway's package, printed after the element (reCAPTCHA's invisible and score modes) |
| `siteKey` | the provider's public key for the site, when it has one |
| `action` | the action it was made for |
| `thirdParty` | whether the check itself goes through someone other than the site |
| `cookies` | whether the provider sets or reads cookies in the visitor's browser |
| `origins` | who the visitor's browser talks to besides the site: `https://challenges.cloudflare.com` |

`html(?string $nonce = null, bool $script = true)` prints it - the nonce of a Content-Security-Policy
on both scripts; `reachesOthers()` is the question to ask before: `thirdParty` or any `origins`.
`WidgetPrinter` prints a provider's script once per page, however many forms, with the first widget
printed; `later()` gives a widget's markup to print when the page shows it (a `WidgetHtml`, cast to a
string, `wrap()` to put it between two pieces of markup): a view built and never shown takes no script.

## Attempt

A token to check (`ChallengeInterface::verify()`).

| Property | |
|---|---|
| `token` | what the widget posted; `''` when nothing was |
| `ip` | the visitor's address, as the application sees it behind its proxies; given to providers that take it |
| `action` | the action the token must have been made for; `null`: not checked |
| `hostname` | the host the widget must have been shown on; `null`: not checked |

`Attempt::fromPost($_POST, $widget, $ip, $hostname)` reads the token under the widget's field
(trimmed; a value that is not a string reads as `''`) and takes its action; `isEmpty()` is true for
`''`.

## Verdict

What a captcha said of a token.

| Property | |
|---|---|
| `passed` | whether the token holds |
| `score` | from 0.0 (a bot) to 1.0 (a person), where the provider scores (reCAPTCHA v3, Enterprise) |
| `action`, `hostname` | as the provider read them |
| `at` | when the challenge was solved, or issued (ALTCHA) |
| `reasons` | why it did not pass, in the family's words: `missing`, `invalid`, `expired`, `duplicate`, `spent` (expired or used: the provider does not say which), `action`, `hostname`, `score` |
| `codes` | the provider's own error codes or reasons: `timeout-or-duplicate`, `DUPE`, `AUTOMATION` |

`Verdict::fail($reason, $codes)`, `failedFor($reason)`, `toArray()` (`at` as an ATOM date). The
reasons are its constants: `Verdict::MISSING`, `INVALID`, `EXPIRED`, `DUPLICATE`, `SPENT`,
`ACTION`, `HOSTNAME`, `SCORE`; a verdict passes exactly when it has none (the conformance suite
checks it of every gateway).

## Submission

What a visitor submitted, as a classifier reads it.

| Property | |
|---|---|
| `content` | what was written |
| `author`, `email`, `url` | the name, the e-mail and the address the author gave - as text |
| `ip`, `userAgent`, `referrer` | from the request the content came with |
| `permalink` | the page it was submitted on, or will be shown at |
| `site` | the site's home page: `https://example.org` - not the current page |
| `language` | the site's language: `fr`, `fr_FR` |
| `type` | `comment`, `reply`, `forum-post`, `blog-post`, `contact-form`, `signup`, `message` (the constants of `Submission`) |
| `date` | when it was submitted |

`identity()` gives the submitter as a list reads them.

## Classification

| Property | |
|---|---|
| `label` | `Label::HAM`, `Label::SPAM` (hold it for a person), `Label::FLAGRANT` (spam beyond doubt: it may be dropped unseen) |
| `reasons` | what the provider gave as reasons or alerts, in its own words |
| `data` | what else it answered: a delay to ask again in |

`isSpam()` (spam or flagrant), `isFlagrant()`, `Classification::ham()`.

`Label` is a string enum: `HAM` (`ham`), `SPAM` (`spam`), `FLAGRANT` (`flagrant`).

## Identity

`ip`, `email`, `name` - any may be missing. `domain()` gives the e-mail's domain (after its last
`@`), lower case, without a trailing dot, or null; `isEmpty()` is true when all three are null or
empty (a list then answers unknown without asking).

## Reputation

What a list knows of an identity.

| Property | |
|---|---|
| `known` | whether the list holds it for abusive, at the gateway's threshold |
| `frequency` | how many times it was reported, where the list counts |
| `confidence` | from 0 to 100: how surely it is abusive, as the list reckons |
| `lastSeen` | when it was last reported |
| `reasons` | which parts matched: `ip`, `email`, `name`, and `disposable`, `tor` |
| `data` | what the list answered, part by part |

`matched($part)`, `Reputation::unknown($data = [])`, `toArray()`. The parts are its constants:
`Reputation::IP`, `EMAIL`, `NAME`, `DISPOSABLE`, `TOR`.

## Capabilities

What a gateway is, before it is asked anything (`$gateway->capabilities()`).

| Property | |
|---|---|
| `challenge`, `classifier`, `reputation` | which of the three questions it answers; `questions()` lists them |
| `thirdParty`, `cookies` | through whom, with what |
| `scores` | whether a Verdict carries a score |
| `actions` | whether it checks the action a token was made for |
| `reports` | whether it takes reports back |
| `reads` | what of an Identity a list reads: `ip`, `email`, `name` |

`questions()` lists `challenge`, `classifier`, `reputation` as claimed; `toArray()` gives them with
every property. Defaults: every question false, `thirdParty` true, everything else false or empty.

## Answer

`Omnishield\Http\Answer` is the one place the gateways call a provider through:
`Answer::send($http, $provider, $method, $url, $options)` (a 10-second timeout unless the options
give one) returns the status, the headers (lower-case names) and the body; no connection, a
timeout, HTTP 5xx and 429 are an `UnreachableException` naming the provider. `header($name)` reads
one header, `json()` the body as a JSON object (anything else: a `ProviderException`). A gateway of
your own calls through it too ([Writing a gateway](gateways.md#writing-a-gateway)).
