<?php

namespace Omnishield\Bridge\Symfony\Validator;

use Omnishield\Exception\UnreachableException;
use Omnishield\Exception\UsageLimitReachedException;
use Omnishield\Model\Attempt;
use Omnishield\Model\Verdict;
use Omnishield\Registry;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\ConstraintValidator;
use Symfony\Component\Validator\Exception\UnexpectedTypeException;
use Symfony\Component\Validator\Exception\UnexpectedValueException;

/**
 * Asks the captcha about the token: the visitor's address from the current
 * request (getClientIp(): set trusted_proxies behind a proxy), the action
 * and the host from the constraint. A provider that does not answer refuses
 * the value unless told to accept it; a key the provider refuses is an
 * error of the site's, not the visitor's: it is thrown.
 */
final class PassesChallengeValidator extends ConstraintValidator
{
    public function __construct(
        private readonly Registry $registry,
        private readonly ?RequestStack $requests = null,
        private readonly ?string $gateway = null,
        private readonly bool $acceptUnreachable = false,
    ) {
    }

    public function validate(mixed $value, Constraint $constraint): void
    {
        if (!$constraint instanceof PassesChallenge) {
            throw new UnexpectedTypeException($constraint, PassesChallenge::class);
        }
        if (null !== $value && !\is_string($value)) {
            throw new UnexpectedValueException($value, 'string');
        }
        $token = trim((string) $value);
        if ('' === $token) {
            $this->context->buildViolation($constraint->missingMessage)->setCode(PassesChallenge::MISSING_ERROR)->addViolation();

            return;
        }

        $request = $this->requests?->getCurrentRequest();
        $hostname = true === $constraint->hostname ? $request?->getHost() : (\is_string($constraint->hostname) ? $constraint->hostname : null);
        $gateway = $constraint->gateway ?? $this->gateway ?? throw new \LogicException('No captcha named: set omnishield.challenge.gateway, or the constraint\'s gateway.');

        try {
            $verdict = $this->registry->challenge($gateway)->verify(new Attempt($token, $request?->getClientIp(), $constraint->action, $hostname));
        } catch (UnreachableException|UsageLimitReachedException) {
            if (!($constraint->unreachable ?? $this->acceptUnreachable)) {
                $this->context->buildViolation($constraint->unreachableMessage)->setCode(PassesChallenge::UNREACHABLE_ERROR)->addViolation();
            }

            return;
        }
        if (!$verdict->passed) {
            $this->context->buildViolation($verdict->failedFor(Verdict::MISSING) ? $constraint->missingMessage : $constraint->message)
                ->setCode($verdict->failedFor(Verdict::MISSING) ? PassesChallenge::MISSING_ERROR : PassesChallenge::FAILED_ERROR)
                ->setCause($verdict)
                ->addViolation();
        }
    }
}
