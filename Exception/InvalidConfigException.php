<?php

namespace Omnishield\Exception;

/** A gateway misconfigured: an option missing, an unknown factory, a submission without what the provider needs. */
final class InvalidConfigException extends \Omnisense\Contracts\Exception\InvalidConfigException implements OmnishieldException
{
}
