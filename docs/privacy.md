---
title: Privacy
order: 35
---

# Privacy: who sees what

A guard looks at a visitor - their address, what they wrote, how their browser behaves. Where
that goes depends on the gateway, and so do what the site's privacy policy says and whether the
visitor is asked first.

| Gateway | What leaves the site | To whom | Cookies | `Widget::origins` |
|---|---|---|---|---|
| `altcha` | nothing: the challenge is issued and checked by the site | - | none | none in Symfony: the bridge serves the widget's script from the site; the CDN (`https://cdn.jsdelivr.net`) in PHP alone, unless the site serves the package's copy |
| `turnstile` | the visitor's browser talks to Cloudflare (its address, browser signals); the site sends the token and the address to `siteverify` | Cloudflare | none: "strictly necessary" signals, by Cloudflare's [Turnstile privacy addendum](https://www.cloudflare.com/turnstile-privacy-policy/) (2025-06-18) | `https://challenges.cloudflare.com` |
| `recaptcha` | the visitor's browser talks to Google; the site sends the token and the address | Google | `_GRECAPTCHA`, "a necessary cookie" by [Google's FAQ](https://developers.google.com/recaptcha/docs/faq); `www.recaptcha.net` instead of `www.google.com` keeps google.com's other cookies away | `https://www.google.com` (or `https://www.recaptcha.net`), `https://www.gstatic.com` |
| `akismet` | the text, the name, the e-mail, the address, the browser, the pages | Akismet (Automattic) | - | - |
| `stopforumspam` | the address, the e-mail (hashed: MD5 by default), the name - what `send` lists | StopForumSpam | - | - |
| `disposable` | nothing: the list is a file of the package | - | - | - |

## What the CNIL says of captchas

The CNIL's questions and answers on cookies and other trackers (question 17, "Le consentement des
utilisateurs doit-il être recueilli pour l'utilisation de systèmes anti-robot ou « CAPTCHA » ?",
page updated on 2026-04-29,
[www.cnil.fr/fr/cookies-et-autres-traceurs/regles/cookies/FAQ](https://www.cnil.fr/fr/cookies-et-autres-traceurs/regles/cookies/FAQ))
draws the line by purpose:

- no consent is needed when the solution's **sole purpose** is to secure the site or an
  authentication mechanism ("ont pour seule finalité la sécurisation du site ou d'un mécanisme
  d'authentification");
- consent is needed when it serves **other purposes, not strictly necessary** - when the provider
  may reuse the data on its own account ("réutiliser les données pour leur propre compte");
- it advises asking the provider how it uses the data, and using **alternatives that need no
  consent** - since a captcha that waits for consent is skipped by whoever refuses.

What follows for a site built on Omnishield - read as the CNIL's answer, not as legal advice:

- **ALTCHA** reaches nobody and sets nothing: no consent to ask, nothing to declare but the
  check itself. Its script is the site's own - served by the Symfony bridge from omnishield/altcha,
  or copied from it in PHP alone - and not even a CDN sees the visitor. It is the default for that
  reason.
- **Turnstile** reaches Cloudflare: name it in the privacy policy; Cloudflare says it uses the
  signals only to tell people from bots. Whether that is "sole purpose" enough is the site's
  call, with its DPO.
- **reCAPTCHA** reaches Google and sets a cookie; what Google does with the data beyond the check
  is the question the CNIL tells the site to ask its provider. Load it after consent. When the
  visitor refuses, the form must still be guarded - by ALTCHA: a refusal must not open the form.
- **Akismet and StopForumSpam** receive the visitor's data from the site's server: name them, say
  what is sent and why (protecting the forms), and keep StopForumSpam off unless the site wants
  it - its address goes to a third party at each check.

## Asking for consent with Omnishield's widgets

```php
$widget = $registry->challenge('forms')->widget('contact');
if ($widget->reachesOthers()) {
    // name $widget->origins to the visitor, wait for their consent, then print $widget->html()
}
```

The origins also go in the page's Content-Security-Policy (`script-src`, `frame-src`,
`connect-src`).
