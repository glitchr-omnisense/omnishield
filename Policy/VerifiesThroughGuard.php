<?php

namespace Omnishield\Policy;

use Omnishield\Model\Attempt;
use Omnishield\Model\Verdict;
use Omnishield\Model\Widget;

/**
 * A captcha's two methods behind the guard: widget() calls nobody and is
 * not counted; each verify() is the operation "verify".
 *
 * @internal
 */
trait VerifiesThroughGuard
{
    public function widget(?string $action = null): Widget
    {
        return $this->gateway->widget($action);
    }

    public function verify(Attempt $attempt): Verdict
    {
        return $this->through('verify', fn () => $this->gateway->verify($attempt), array_filter(['ip' => $attempt->ip]));
    }
}
