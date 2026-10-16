<?php

namespace Omnishield\Tests;

use Omnishield\Config;
use Omnishield\Model\Attempt;
use Omnishield\Model\Capabilities;
use Omnishield\Model\Classification;
use Omnishield\Model\Identity;
use Omnishield\Model\Label;
use Omnishield\Model\Reputation;
use Omnishield\Model\Submission;
use Omnishield\Model\Verdict;
use Omnishield\Model\Widget;
use Omnishield\WidgetPrinter;
use PHPUnit\Framework\TestCase;

final class ModelTest extends TestCase
{
    public function testAWidgetPrintsItsScriptItsElementAndItsInlineScript(): void
    {
        $widget = new Widget('cf-turnstile-response', 'https://challenges.cloudflare.com/turnstile/v0/api.js', ['async' => true, 'defer' => true], 'div', ['class' => 'cf-turnstile', 'data-sitekey' => '1x00000000000000000000AA', 'data-action' => 'contact', 'data-cdata' => false], inline: 'void 0', thirdParty: true, origins: ['https://challenges.cloudflare.com']);

        self::assertSame('<script src="https://challenges.cloudflare.com/turnstile/v0/api.js" async defer></script><div class="cf-turnstile" data-sitekey="1x00000000000000000000AA" data-action="contact"></div><script>void 0</script>', $widget->html());
        self::assertSame('<script src="https://challenges.cloudflare.com/turnstile/v0/api.js" async defer nonce="n0nce"></script><div class="cf-turnstile" data-sitekey="1x00000000000000000000AA" data-action="contact"></div><script nonce="n0nce">void 0</script>', $widget->html('n0nce'));
        self::assertTrue($widget->reachesOthers());
        self::assertStringNotContainsString('<script src', $widget->html(script: false));
    }

    public function testAttributesAreEscapedAndAnInputIsNotClosed(): void
    {
        $widget = new Widget('t', tag: 'input', attributes: ['type' => 'hidden', 'name' => 't', 'value' => '"><script>']);

        self::assertSame('<input type="hidden" name="t" value="&quot;&gt;&lt;script&gt;">', $widget->html());
        self::assertFalse($widget->reachesOthers());
    }

    public function testAPrinterPrintsAScriptOncePerPage(): void
    {
        $printer = new WidgetPrinter();
        $widget = new Widget('altcha', '/altcha.min.js', ['type' => 'module'], 'altcha-widget');

        self::assertSame('<script src="/altcha.min.js" type="module"></script><altcha-widget></altcha-widget>', $printer->print($widget));
        self::assertSame('<altcha-widget></altcha-widget>', $printer->print($widget), 'the second form of the page');
        $printer->reset();
        self::assertStringStartsWith('<script', $printer->print($widget), 'the next page');
    }

    public function testAWidgetAskedForLaterTakesTheScriptWhenItIsPrinted(): void
    {
        $printer = new WidgetPrinter();
        $widget = new Widget('altcha', '/altcha.min.js', ['type' => 'module'], 'altcha-widget');

        $unseen = $printer->later($widget);
        $shown = $printer->later($widget)->wrap('<div data-fallback>', '</div>');
        self::assertSame('<div data-fallback><script src="/altcha.min.js" type="module"></script><altcha-widget></altcha-widget></div>', (string) $shown, 'the one printed first, not the one asked for first');
        self::assertSame((string) $shown, (string) $shown, 'read twice, the same markup');
        self::assertSame('<altcha-widget></altcha-widget>', (string) $unseen);
        self::assertSame($widget, $unseen->widget);
    }

    public function testAnAttemptIsReadFromTheFormUnderTheWidgetsField(): void
    {
        $widget = new Widget('cf-turnstile-response', action: 'signup');
        $attempt = Attempt::fromPost(['cf-turnstile-response' => " XXXX.DUMMY.TOKEN.XXXX\n", 'email' => 'a@b.c'], $widget, '192.0.2.10', 'example.org');

        self::assertSame(['XXXX.DUMMY.TOKEN.XXXX', '192.0.2.10', 'signup', 'example.org'], [$attempt->token, $attempt->ip, $attempt->action, $attempt->hostname]);
        self::assertTrue(Attempt::fromPost([], $widget)->isEmpty());
        self::assertTrue(Attempt::fromPost(['cf-turnstile-response' => ['an', 'array']], $widget)->isEmpty());
    }

    public function testAVerdictSaysWhy(): void
    {
        $verdict = Verdict::fail(Verdict::SPENT, ['timeout-or-duplicate']);

        self::assertFalse($verdict->passed);
        self::assertTrue($verdict->failedFor(Verdict::SPENT));
        self::assertFalse($verdict->failedFor(Verdict::INVALID));
        self::assertSame(['at' => null, 'passed' => false, 'score' => null, 'action' => null, 'hostname' => null, 'reasons' => ['spent'], 'codes' => ['timeout-or-duplicate']], $verdict->toArray());
    }

    public function testAClassificationIsHamSpamOrFlagrant(): void
    {
        self::assertFalse(Classification::ham()->isSpam());
        self::assertTrue((new Classification(Label::SPAM))->isSpam());
        self::assertFalse((new Classification(Label::SPAM))->isFlagrant());
        self::assertTrue((new Classification(Label::FLAGRANT))->isSpam());
        self::assertTrue((new Classification(Label::FLAGRANT))->isFlagrant());
    }

    public function testAnIdentityGivesItsDomainAndASubmissionItsIdentity(): void
    {
        self::assertSame('mailinator.com', (new Identity(email: 'Someone@Mailinator.COM.'))->domain());
        self::assertSame('sub.example.org', (new Identity(email: 'a@b@sub.example.org'))->domain());
        self::assertNull((new Identity(email: 'no-at-sign'))->domain());
        self::assertNull((new Identity(email: 'trailing@'))->domain());
        self::assertTrue((new Identity())->isEmpty());

        $submission = new Submission('Hello', 'Camille', 'camille@example.org', ip: '192.0.2.10', date: new \DateTimeImmutable('2026-10-07T10:00:00+02:00'));
        self::assertEquals(new Identity('192.0.2.10', 'camille@example.org', 'Camille'), $submission->identity());
        self::assertSame(Submission::COMMENT, $submission->type);
    }

    public function testAReputationSaysWhichPartMatched(): void
    {
        $reputation = new Reputation(true, 91, 95.29, new \DateTimeImmutable('2026-09-30 23:22:50', new \DateTimeZone('UTC')), [Reputation::IP, Reputation::TOR]);

        self::assertTrue($reputation->matched(Reputation::TOR));
        self::assertFalse($reputation->matched(Reputation::EMAIL));
        self::assertSame('2026-09-30T23:22:50+00:00', $reputation->toArray()['lastSeen']);
        self::assertFalse(Reputation::unknown()->known);
    }

    public function testCapabilitiesNameTheQuestions(): void
    {
        self::assertSame(['challenge'], (new Capabilities(challenge: true))->questions());
        self::assertSame(['classifier', 'reputation'], (new Capabilities(classifier: true, reputation: true))->toArray()['questions']);
    }

    public function testConfigReadsWhatEnvironmentVariablesGive(): void
    {
        $config = new Config(['a' => 'true', 'b' => '0', 'c' => true, 'list' => 'a.org, b.org,,', 'empty' => '']);

        self::assertTrue($config->bool('a'));
        self::assertFalse($config->bool('b'));
        self::assertTrue($config->bool('c'));
        self::assertFalse($config->bool('missing'));
        self::assertSame(['a.org', 'b.org'], $config->list('list'));
        self::assertNull($config->string('empty'));
        self::assertNull($config->string('missing'));
    }
}
