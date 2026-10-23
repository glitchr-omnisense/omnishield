<?php

namespace Omnishield\Tests;

use Omnishield\Bridge\Symfony\OmnishieldBundle;
use Omnishield\Registry;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpKernel\Bundle\AbstractBundle;

/**
 * Omnishield outside Symfony: the harness's bare script (docker/harness/bin/bare)
 * run in a PHP process of its own - this one has loaded the bridge's tests -
 * builds the registry by hand and asks each gateway installed a whole
 * question, on the providers' recorded answers (nothing leaves the machine),
 * then reports every class and file PHP loaded on the way. None may be a
 * framework's; of Symfony, only the HTTP client the providers are called
 * through.
 */
final class BareTest extends TestCase
{
    private const FRAMEWORK = '~^(?:Symfony\\\\Component\\\\(?:DependencyInjection|Config|HttpKernel|HttpFoundation|Form|Validator|Routing)|Symfony\\\\Bundle|Symfony\\\\Bridge|Doctrine|Twig)\\\\~';
    private const FRAMEWORK_FILES = '~/vendor/(?:symfony/(?:dependency-injection|config|http-kernel|http-foundation|form|validator|routing|[a-z-]*bundle|[a-z-]*bridge)|doctrine|twig)/~';

    /** @var array<string, mixed>|null */
    private static ?array $report = null;

    /** @return array<string, mixed> */
    private static function report(): array
    {
        if (null === self::$report) {
            [$status, self::$report] = self::php([__DIR__.'/../docker/harness/bin/bare', '--json']);
            self::assertSame(0, $status);
        }

        return self::$report;
    }

    public function testTheRegistryIsBuiltByHandAndNoClassOfAFrameworkIsLoaded(): void
    {
        $report = self::report();

        self::assertFalse($report['live']);
        self::assertContains(Registry::class, $report['symbols'], 'the registry was built there');
        self::assertSame([], self::framework($report), 'no class nor file of a framework');
        $symfony = array_values(array_unique(array_map(static fn (string $s) => implode('\\', \array_slice(explode('\\', $s), 0, 3)), preg_grep('~^Symfony\\\\(?!Polyfill)~', $report['symbols']))));
        self::assertSame([], array_diff($symfony, ['Symfony\\Component\\HttpClient', 'Symfony\\Contracts\\HttpClient', 'Symfony\\Contracts\\Service']), 'of Symfony, the HTTP client alone');
        $installed = array_values(array_filter(array_column(require __DIR__.'/../docker/harness/plugins.php', 1), 'class_exists'));
        foreach ($installed as $factory) {
            self::assertContains($factory, $report['symbols'], 'every gateway package installed, its factory built');
        }
        self::assertCount(\count($installed) + 1, $report['gateways'], 'and the fixed one');
        self::assertTrue($report['fixed']);
    }

    public function testEachGatewayAnswersAWholeQuestion(): void
    {
        $report = self::report();

        if (isset($report['altcha'])) {
            self::assertTrue($report['altcha']['first']['passed'], 'issued, solved, verified');
            self::assertSame(['duplicate'], $report['altcha']['again']['reasons'], 'the same solution twice');
            self::assertSame(['action'], $report['altcha']['other_action']['reasons']);
            self::assertSame([false, false], [$report['altcha']['widget']['third_party'], $report['altcha']['widget']['cookies']]);
        }
        if (isset($report['turnstile'])) {
            self::assertTrue($report['turnstile']['passes']['passed']);
            self::assertSame(['invalid'], $report['turnstile']['fails']['reasons']);
            self::assertSame(['spent'], $report['turnstile']['spent']['reasons']);
        }
        if (isset($report['recaptcha'])) {
            self::assertTrue($report['recaptcha']['test_keys']['passed']);
            self::assertSame('testkey.google.com', $report['recaptcha']['test_keys']['hostname']);
            self::assertSame(['missing'], $report['recaptcha']['empty']['reasons']);
        }
        if (isset($report['akismet'])) {
            self::assertSame(['ham', 'flagrant'], [$report['akismet']['ham'], $report['akismet']['spam']]);
        }
        if (isset($report['stopforumspam'])) {
            self::assertTrue($report['stopforumspam']['tor_exit']['known']);
            self::assertSame(['ip', 'tor'], $report['stopforumspam']['tor_exit']['reasons']);
            self::assertFalse($report['stopforumspam']['documentation']['known']);
        }
        if (isset($report['disposable'])) {
            self::assertSame(['someone@mailinator.com' => 'mailinator.com', 'someone@eu.yopmail.com' => 'yopmail.com', 'someone@gmail.com' => null], $report['disposable']);
        }
        self::assertNotEmpty(array_intersect(['altcha', 'turnstile', 'recaptcha', 'akismet', 'stopforumspam', 'disposable'], array_keys($report)), 'at least one gateway package is installed beside this one');
    }

    /** The check is not blind: the same report, once the bundle is loaded, names the framework. */
    public function testTheBundleDoesLoadTheFramework(): void
    {
        if (!class_exists(AbstractBundle::class)) {
            self::markTestSkipped('symfony/http-kernel is not installed.');
        }
        [$status, $report] = self::php(['-r', 'require getenv("OMNISHIELD_AUTOLOAD"); class_exists($argv[1]) || exit(2); echo json_encode(["symbols" => [...get_declared_classes(), ...get_declared_interfaces(), ...get_declared_traits()], "files" => get_included_files()]);', '--', OmnishieldBundle::class]);

        self::assertSame(0, $status);
        $framework = self::framework($report);
        self::assertContains(AbstractBundle::class, $framework);
        self::assertNotEmpty(preg_grep('~/symfony/http-kernel/~', $framework));
    }

    /**
     * @param array{symbols: list<string>, files: list<string>} $report
     *
     * @return list<string> the classes, interfaces, traits and files of a framework among those loaded
     */
    private static function framework(array $report): array
    {
        // Composer includes every installed package's "files" (Twig's functions, here installed for the
        // bridge's tests) before anything runs: loaded by the autoloader, not by Omnishield.
        $eager = require self::vendorDir().'/composer/autoload_files.php';
        $files = array_diff(array_map('realpath', $report['files']), array_map('realpath', array_values($eager)));

        return [...array_values(preg_grep(self::FRAMEWORK, $report['symbols'])), ...array_values(preg_grep(self::FRAMEWORK_FILES, $files))];
    }

    /**
     * The vendor directory Omnishield is loaded from in this run. Not the one of
     * Composer's ClassLoader class: under symfony/phpunit-bridge (an application's
     * simple-phpunit) that class is the copy of PHPUnit's own install, whose
     * autoloader knows neither Omnishield nor the HTTP client - the script
     * failed there on MockHttpClient, and the bundle was not found.
     */
    private static function vendorDir(): string
    {
        foreach (\Composer\Autoload\ClassLoader::getRegisteredLoaders() as $vendorDir => $loader) {
            if ($loader->findFile(Registry::class)) {
                return $vendorDir;
            }
        }
        self::fail('No registered autoloader knows '.Registry::class.'.');
    }

    /**
     * Runs PHP apart, on the autoloader of this run.
     *
     * @param list<string> $arguments
     *
     * @return array{int, array<string, mixed>} the exit status, the JSON printed
     */
    private static function php(array $arguments): array
    {
        $autoload = self::vendorDir().'/autoload.php';
        $process = proc_open([\PHP_BINARY, ...$arguments], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, ['OMNISHIELD_AUTOLOAD' => $autoload] + getenv());
        self::assertIsResource($process);
        $out = (string) stream_get_contents($pipes[1]);
        $err = (string) stream_get_contents($pipes[2]);
        $status = proc_close($process);
        $report = json_decode($out, true);
        self::assertIsArray($report, 'PHP exited '.$status.': '.$err.$out);

        return [$status, $report];
    }
}
