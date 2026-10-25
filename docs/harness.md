---
title: The Docker harness
order: 70
---

## The Docker harness

`docker/` runs this package with every `omnishield/*` gateway installed - from GitHub (branch
1.x), or from the checkouts beside this one when `OMNISHIELD_PLUGINS=../..` is set in
`docker/.env` - with a console and a script in bare PHP.

```sh
cd docker && cp .env.dist .env       # the keys you have; none is needed
docker compose run --rm omnishield gateways
```

| Command | |
|---|---|
| `gateways` | the gateways installed and configured, what each answers, through whom, with what |
| `widget <gateway>` | what the page shows for a captcha: its parts (JSON) and its markup (`--action`) |
| `solve <gateway>` | ALTCHA: a challenge issued and solved here, as the widget would: the token to post (`--action`) |
| `verify <gateway> <token>` | whether a token holds (JSON): `--ip`, `--action`, `--hostname`; exits 1 when refused |
| `classify <gateway> <text>` | whether a text is spam (JSON): `--author`, `--email`, `--ip`, `--type`; `--report spam\|ham` teaches instead |
| `lookup <gateway>` | what a list knows (JSON): `--ip`, `--email`, `--name` |
| `bare` | plain PHP: the registry built by hand, each gateway asked a whole question, what PHP loaded |
| `test` | every package's tests |

The console calls the providers for real; `--recorded` answers from the packages' recorded
answers instead. A gateway whose settings are not in `.env` runs on its example ones: the
providers' own testing keys - Cloudflare's dummy keys, Google's v2 test keys -, a fixed key for
ALTCHA, and for Akismet a key only the recorded answers accept.

```
$ docker compose run --rm omnishield verify turnstile XXXX.DUMMY.TOKEN.XXXX --ip 192.0.2.10
{
    "at": "2026-10-07T00:55:07+00:00",
    "passed": true,
    "score": null,
    "action": null,
    "hostname": "example.com",
    "reasons": [],
    "codes": [
        "result_with_testing_key"
    ]
}

$ T=$(docker compose run --rm -T omnishield solve altcha --action contact)
$ docker compose run --rm omnishield verify altcha "$T" --action contact     # passes: each run has its own memory of spent tokens
```

### Bare: no bundle, no container

The console is a `symfony/console` application over a registry built by hand; `bare` is less
still - one PHP script, `docker/harness/bin/bare`, that requires the autoloader and nothing else.
It builds the `Registry` from the gateway packages installed, asks each what it answers, then a
whole question of each, and lists what PHP loaded - exit 1 if a class of a framework is among it
(`Symfony\Component\DependencyInjection`, `Config`, `HttpKernel`, `HttpFoundation`, `Form`,
`Validator`, `Routing`, a bundle or a bridge, Doctrine, Twig):

```
$ docker compose run --rm omnishield bare --live
Omnishield in bare PHP: the registry built by hand, no bundle, no container; the providers themselves.

  altcha         ALTCHA: answers challenge; third party no, cookies no (example settings)
  turnstile      Cloudflare Turnstile: answers challenge; third party yes, cookies no (example settings)
  recaptcha      Google reCAPTCHA v2 (checkbox): answers challenge; third party yes, cookies yes (example settings)
  akismet        Akismet: answers classifier; third party yes, cookies no (example settings)
  stopforumspam  StopForumSpam: answers reputation; third party yes, cookies no
  disposable     Disposable e-mail domains: answers reputation; third party no, cookies no
  fixed          Fixed: passes everything: answers challenge, classifier, reputation; third party no, cookies no

altcha: a challenge issued, solved here in 0.171s
  first post: passed; the same again: refused (duplicate); issued for another action: refused (action)
  the widget: <altcha-widget> posting "altcha", third party no, scripts from https://cdn.jsdelivr.net

turnstile, Cloudflare's testing keys:
  1x: passed; 2x: refused (invalid; invalid-input-response); 3x: refused (spent; timeout-or-duplicate)

recaptcha, Google's v2 test keys: passed from testkey.google.com; an empty token: refused (missing) (nothing sent)

akismet: skipped, no AKISMET_API_KEY: the recorded answers only accept the example key

stopforumspam: 185.220.101.1 known (frequency 91, confidence 95.29, ip, tor)
  91.186.18.61 + g2fsehis5e@mail.ru (hashed): unknown (frequency 0, confidence 0)

disposable: someone@mailinator.com disposable (mailinator.com); someone@eu.yopmail.com disposable (yopmail.com); someone@gmail.com not on the list

fixed: passed

Loaded from Symfony: Symfony\Component\HttpClient, Symfony\Contracts\HttpClient, Symfony\Contracts\Service
Classes of a framework (DependencyInjection, Config, HttpKernel, HttpFoundation, Form, Validator, Routing, a bundle or bridge, Doctrine, Twig): none
```

(2026-10-07)

Without `--live` the same runs on the recorded answers: nothing leaves the machine.
`bare --json` prints the same whole, every class and file loaded: `Tests/BareTest.php` runs it in a
process of its own and checks the list - of Symfony, only the HTTP client the providers are called
through.

The image is `php:8.4-cli-alpine` with Composer; the harness's packages live in the `harness`
volume of the `omnishield-harness` project. The gateways are cloned from GitHub as plain git
repositories over HTTPS: no GitHub API, no ssh in the image.

### The workspace, without the harness

The packages also sit side by side in a Composer workspace (`~/Sites/omnishield`: a `composer.json`
whose path repositories link `core` and the six providers into one `vendor/`, and a
`phpunit.xml.dist` with a test suite per package). The examples of this manual were run there on
2026-10-26, offline, in a PHP 8.4 container with the workspace mounted read-only and no network
(`docker run --rm --network none -v <workspace>:/ws:ro ... php /scratch/example.php`, the
autoloader `/ws/vendor/autoload.php`): the harness's image, `omniscient/omnishield-harness`, was not
built on that machine. The workspace's suite ran there too: 159 tests, 1231 assertions, green.

The harness's service uses Compose's default network: `docker compose down -v` removes it with the
`harness` volume once done.
