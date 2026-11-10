<?php

namespace Omnishield\Tests;

use Omnishield\ChallengeInterface;
use Omnishield\Exception\InvalidConfigException;
use Omnishield\Exception\NotSupportedException;
use Omnishield\Registry;
use Omnishield\Testing\FixedGatewayFactory;
use PHPUnit\Framework\TestCase;

final class RegistryTest extends TestCase
{
    private function registry(): Registry
    {
        return new Registry([new StubFactory(), new FixedGatewayFactory()], [
            'forms' => ['factory' => 'stub', 'options' => ['secret' => 's3cret']],
            'tests' => ['factory' => 'fixed'],
            'broken' => ['factory' => 'stub'],
            'unknown' => ['factory' => 'nowhere'],
        ]);
    }

    public function testAGatewayIsBuiltOnceFromItsFactoryAndOptions(): void
    {
        $registry = $this->registry();

        self::assertSame($registry->get('forms'), $registry->get('forms'));
        self::assertInstanceOf(StubGateway::class, $forms = $registry->get('forms'));
        self::assertSame('s3cret', $forms->secret);
        self::assertNotSame($registry->get('forms'), $registry->create('forms', ['secret' => 'typed-in-a-back-office']));
        self::assertInstanceOf(StubGateway::class, $typed = $registry->create('forms', ['secret' => 'typed-in-a-back-office']));
        self::assertSame('typed-in-a-back-office', $typed->secret);
        self::assertSame(['forms', 'tests', 'broken', 'unknown'], $registry->names());
        self::assertSame(['stub', 'fixed'], $registry->factories());
        self::assertSame(['secret' => 's3cret'], $registry->options('forms'));
        self::assertTrue($registry->has('forms'));
        self::assertFalse($registry->has('nope'));
    }

    public function testEachQuestionIsAskedOfAGatewayThatAnswersIt(): void
    {
        $registry = $this->registry();

        self::assertInstanceOf(ChallengeInterface::class, $registry->challenge('forms'));
        self::assertSame($registry->get('tests'), $registry->classifier('tests'), 'the fixed gateway answers all three');
        self::assertSame($registry->get('tests'), $registry->reputation('tests'));

        $this->expectException(NotSupportedException::class);
        $this->expectExceptionMessage('The "forms" gateway (Stub) does not answer "is this content spam?"; it answers: challenge.');
        $registry->classifier('forms');
    }

    public function testAMissingOptionShowsWhenTheGatewayIsBuilt(): void
    {
        $this->expectException(InvalidConfigException::class);
        $this->expectExceptionMessage('The "stub" gateway needs: secret.');
        $this->registry()->get('broken');
    }

    public function testAnUnknownFactoryOrGatewayIsNamed(): void
    {
        try {
            $this->registry()->get('unknown');
            self::fail();
        } catch (InvalidConfigException $e) {
            self::assertSame('No "nowhere" factory for the "unknown" gateway; installed: stub, fixed.', $e->getMessage());
        }
        $this->expectExceptionMessage('No "nope" gateway; configured: forms, tests, broken, unknown.');
        $this->registry()->get('nope');
    }
}
