<?php

namespace Omnishield\Policy;

use Omnisense\Contracts\Policy\PolicyGuard;
use Omnishield\ClassifierInterface;
use Omnishield\Model\Classification;
use Omnishield\Model\Submission;

/** A spam classifier behind its guard: each classify() is the operation "classify", each report() "report". */
final class GuardedClassifier extends Guarded implements ClassifierInterface
{
    public function __construct(private readonly ClassifierInterface $gateway, PolicyGuard $guard)
    {
        parent::__construct($gateway, $guard);
    }

    public function classify(Submission $submission): Classification
    {
        return $this->through('classify', fn () => $this->gateway->classify($submission), array_filter(['ip' => $submission->ip]));
    }

    public function report(Submission $submission, bool $spam): void
    {
        $this->through('report', fn () => $this->gateway->report($submission, $spam), array_filter(['ip' => $submission->ip]));
    }

    public function inner(): ClassifierInterface
    {
        return $this->gateway;
    }
}
