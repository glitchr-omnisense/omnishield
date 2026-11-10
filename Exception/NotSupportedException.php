<?php

namespace Omnishield\Exception;

use Omnishield\GatewayInterface;

/**
 * The gateway does not do that: a captcha asked whether content is spam, a
 * list that takes no report. Its Capabilities say so beforehand.
 */
final class NotSupportedException extends \Omnisense\Contracts\Exception\NotSupportedException implements OmnishieldException
{
    public static function question(GatewayInterface $gateway, string $name, string $question): self
    {
        return new self(\sprintf('The "%s" gateway (%s) does not answer "%s?"; it answers: %s.', $name, $gateway->getTitle(), $question, implode(', ', $gateway->capabilities()->questions()) ?: 'nothing'));
    }

    public static function operation(string $gateway, string $operation, string $why = ''): self
    {
        return new self(\sprintf('The "%s" gateway does not %s%s.', $gateway, $operation, '' !== $why ? ': '.$why : ''));
    }
}
