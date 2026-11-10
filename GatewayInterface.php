<?php

namespace Omnishield;

use Omnisense\Contracts\Provider\ProviderInterface;
use Omnishield\Model\Capabilities;

/**
 * One guard, configured: a captcha, a spam classifier, a list of known
 * abusers. It answers one of three questions - ChallengeInterface (is this
 * token valid?), ClassifierInterface (is this content spam?),
 * ReputationInterface (are this address, this e-mail, this name known for
 * abuse?) - and its Capabilities say which, before anything is asked.
 *
 * A gateway answers. It does not decide what to do with a refusal, nor with
 * a provider that does not answer: the application does.
 *
 * It is an omnisense/contracts provider: its Capabilities carry its usage
 * policy (policy()).
 */
interface GatewayInterface extends ProviderInterface
{
    /** The factory's name: "altcha", "turnstile", "akismet"... */
    public function getName(): string;

    public function getTitle(): string;

    /** Which questions it answers, through whom, with what. */
    public function capabilities(): Capabilities;
}
