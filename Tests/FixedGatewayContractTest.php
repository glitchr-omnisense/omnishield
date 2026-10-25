<?php

namespace Omnishield\Tests;

use Omnishield\GatewayFactoryInterface;
use Omnishield\Model\Attempt;
use Omnishield\Model\Identity;
use Omnishield\Model\Submission;
use Omnishield\Testing\FixedGatewayFactory;
use Omnishield\Testing\GatewayContractTests;
use PHPUnit\Framework\TestCase;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/** The fixed gateway, an application's stand-in, keeps the contract it stands in for. */
final class FixedGatewayContractTest extends TestCase
{
    use GatewayContractTests;

    protected function factory(HttpClientInterface $http): GatewayFactoryInterface
    {
        return new FixedGatewayFactory();
    }

    protected function options(): array
    {
        return ['pass' => true];
    }

    protected function recordedCases(): iterable
    {
        $identity = new Identity('192.0.2.10', 'camille@example.org', 'camille');
        $submission = new Submission('Thank you, this helped.', 'Camille', ip: '192.0.2.10');

        yield 'a token passes' => [new Attempt('omnishield-fixed-token', '192.0.2.10', 'contact'), true];
        yield 'a token is refused' => [new Attempt('omnishield-fixed-token'), false, [], ['pass' => false]];
        yield 'a submission is ham' => [$submission, true];
        yield 'a submission is spam' => [$submission, false, [], ['pass' => 'false']];
        yield 'an identity is unknown' => [$identity, true];
        yield 'an identity is known' => [$identity, false, [], ['pass' => false]];
    }
}
