---
title: The three questions
order: 30
---

## The three questions

A gateway answers one question - its `Capabilities` say which - and the `Registry` gives it as the
contract that asks it: `challenge($name)`, `classifier($name)`, `reputation($name)`, or a
`NotSupportedException` naming what it does answer.

| Contract | The question | Methods |
|---|---|---|
| `ChallengeInterface` | is this token valid? | `widget(?string $action): Widget`, `verify(Attempt): Verdict` |
| `ChallengeIssuerInterface` | the same, for a captcha whose challenge the site issues | `issue(?string $action): array` besides |
| `ClassifierInterface` | is this content spam? | `classify(Submission): Classification`, `report(Submission, bool $spam)` |
| `ReputationInterface` | are this address, this e-mail, this name known for abuse? | `lookup(Identity): Reputation` |

| | altcha | turnstile | recaptcha | akismet | stopforumspam | disposable |
|---|---|---|---|---|---|---|
| Answers | challenge (issuer) | challenge | challenge | classifier | reputation | reputation |
| Called | nothing | `siteverify` | `siteverify`, Enterprise `assessments` | `comment-check`, `submit-spam`, `submit-ham`, `verify-key` | `api`, `add` (reports) | nothing |
| Third party | none | Cloudflare | Google | Akismet | StopForumSpam | none |
| Cookies | none | none | `_GRECAPTCHA` | - | - | - |
| Score | no | no | v3, Enterprise | - | confidence | 100 when listed |
| Action checked | yes, signed in the challenge | yes | v3, Enterprise | - | - | - |
| Spent tokens | the site's store | Cloudflare refuses them | Google refuses them | - | - | - |
| Needs | a key of the site's | site key, secret | site key, secret; Enterprise: project, API key | an API key | nothing; a key to report | nothing |

### A captcha

```php
$captcha = $registry->challenge('forms');
$widget = $captcha->widget('contact');

// The page
echo $widget->html($cspNonce);

// The handler
$verdict = $captcha->verify(Attempt::fromPost($_POST, $widget, $clientIp, 'www.example.org'));
if (!$verdict->passed) {
    // $verdict->reasons: missing, invalid, expired, duplicate, spent, action, hostname, score
}
```

- **An empty token is never sent**: it fails as `missing` at once (Google's test secret would pass
  it).
- **The action** is what the form is for - `contact`, `signup`. ALTCHA signs it into the
  challenge, Turnstile and reCAPTCHA v3 carry it in the token: the gateway checks it back when the
  attempt names one. Keep it a short word of letters, digits and underscores (reCAPTCHA's rule).
- **The host** is checked when the attempt names one; the providers' testing keys answer for any
  host (`example.com`, `testkey.google.com`), so it is not checked against theirs.
- **A token serves once.** Turnstile and reCAPTCHA refuse a second use on their side (`spent`);
  ALTCHA cannot, and the gateway's store does (`duplicate`).

A captcha the site issues itself (`ChallengeIssuerInterface`: ALTCHA) carries a fresh challenge in
its widget, or the address it is fetched from when `challenge_url` is set: `issue()` is what that
address answers - the Symfony bridge has the route.

### A classifier

```php
$classification = $registry->classifier('comments')->classify(new Submission(
    content: $comment->text, author: $comment->name, email: $comment->email, url: $comment->website,
    ip: $request->ip, userAgent: $request->userAgent, referrer: $request->referer,
    permalink: 'https://example.org/blog/a-post', site: 'https://example.org', language: 'fr',
    type: Submission::COMMENT, date: $comment->postedAt,
));

match ($classification->label) {
    Label::HAM => $comment->publish(),
    Label::SPAM => $comment->holdForModeration(),
    Label::FLAGRANT => $comment->discard(),
};

// later, a moderator corrects it
$registry->classifier('comments')->report($submission, spam: true);
```

Send the submission as it was classified: the classifier finds it by what it was told.

### A list

```php
$reputation = $registry->reputation('reported')->lookup(new Identity($ip, $email, $name));
$reputation->known;                // at the gateway's threshold
$reputation->reasons;              // ['ip', 'tor'], ['email', 'disposable']
$reputation->confidence;           // 0-100
```

A list reads the parts its `Capabilities::$reads` name and ignores the others; it is asked about
the people who submit a form, never about every visitor.

### Writing a gateway

A package `omnishield/<provider>`: a factory extending `Omnishield\GatewayFactory` that fills a
`Config` - its name, title, the options it needs and their defaults - and builds the gateway, a
class implementing the contract of its question:

```php
final class HcaptchaGatewayFactory extends GatewayFactory
{
    // A provider reached over HTTP takes its client here: the application's, a MockHttpClient in a test.
    public function __construct(private readonly ?HttpClientInterface $http = null)
    {
    }

    protected function populate(Config $c): void
    {
        $c->defaults([
            'omnishield.factory_name' => 'hcaptcha',
            'omnishield.factory_title' => 'hCaptcha',
            'omnishield.required_options' => ['site_key', 'secret'],
        ]);
    }

    protected function build(Config $c): GatewayInterface
    {
        return new HcaptchaGateway($this->http ?? HttpClient::create(), (string) $c['site_key'], (string) $c['secret']);
    }
}
```

Call the provider through `Omnishield\Http\Answer::send()`: no answer, a server error or a quota
exceeded become an `UnreachableException`, and the rest comes back for the gateway to read. Turn
a refusal into a `Verdict`, a refused key into an `InvalidKeyException`, and leave out what the
provider's documentation does not show. An HTTP 401 or 403 is the provider refusing the site's
credentials: an `InvalidKeyException` when the gateway sends a key or a secret, a
`ProviderException` when it sends none (StopForumSpam's lookup), whatever the body says. Test it on `MockHttpClient` with answers recorded from the
provider (`Tests/Fixtures/*.json`), and say in its documentation what was verified for real and
what was not.

### Conformance suite

`Omnishield\Testing\GatewayContractTests` is the family's contract as PHPUnit tests: every gateway
package runs it, offline, in a `Tests/<Name>ContractTest.php` of its own. The test case gives the
factory (handed a `MockHttpClient`: no test reaches the network), the options that build a working
gateway, and recorded cases - an input, whether the gateway accepts it, the provider's recorded
answers, options of the case's own:

```php
use Omnishield\Testing\GatewayContractTests;

final class HcaptchaContractTest extends TestCase
{
    use GatewayContractTests;

    protected function factory(HttpClientInterface $http): GatewayFactoryInterface
    {
        return new HcaptchaGatewayFactory($http);
    }

    protected function options(): array
    {
        return ['site_key' => '10000000-ffff-ffff-ffff-000000000001', 'secret' => '0x0000000000000000000000000000000000000000'];
    }

    protected function recordedCases(): iterable
    {
        yield 'passes' => [new Attempt('10000000-aaaa-bbbb-cccc-000000000001'), true, [new MockResponse(file_get_contents(__DIR__.'/Fixtures/siteverify-passes.json'))]];
        yield 'spent' => [new Attempt('10000000-aaaa-bbbb-cccc-000000000001'), false, [new MockResponse(file_get_contents(__DIR__.'/Fixtures/siteverify-spent.json'))]];
    }
}
```

An input is an `Attempt` (a captcha), a `Submission` (a classifier) or an `Identity` (a list), or a
closure that makes one from the gateway (an ALTCHA token solved from its `issue()`). Each question
the gateway's `Capabilities` claim needs a case it accepts and one it refuses. What it checks:

| | |
|---|---|
| Factory and registry | the factory's name is the gateway's; the `Registry` builds it from `{factory, options}`, once |
| Options | each required option left out, or empty, is an `InvalidConfigException` naming it |
| Capabilities | each question claimed is its interface, and only those; the `Registry` refuses the others with a `NotSupportedException`; `reads` only for a list; actions and scores only for a captcha; a classifier without `reports` throws `NotSupportedException` on `report()` |
| Nothing for nothing | an empty token is a `Verdict` *missing*, an empty `Identity` unknown, without a request; a gateway that says nothing leaves the site (`thirdParty: false`) makes no HTTP request at all |
| A captcha | its widget: a field, `thirdParty` and `cookies` as the `Capabilities` say, the action asked for; an issuer issues a fresh JSON challenge each time; a localizable one stays itself |
| Invariants | a `Verdict` passes exactly when it has no reason, its reasons are the family's constants, a score lies in 0..1 and only where the gateway scores; a `Classification` has a `Label`; a known `Reputation` says which parts matched (among those it reads, or *disposable*, *tor*), an unknown one none, its confidence in 0..100 |
| Recorded cases | answered as recorded; every recorded answer read, no more asked for |
| Errors (third party) | no answer, HTTP 5xx and 429: `UnreachableException`; HTTP 401 and 403: `InvalidKeyException` (`ProviderException` for a gateway that sends no key: override `sendsCredentials()`); an answer it cannot read: `ProviderException` - each naming the provider, never a `TypeError` or a PHP warning |

Hooks with defaults: `requiredOptions()` (what a `GatewayFactory` says it requires),
`sendsCredentials()` (whether any option is required), `sampleSubmission()`, `sampleIdentity()`.
It needs `phpunit/phpunit` and `symfony/http-client` in the package's `require-dev`.
