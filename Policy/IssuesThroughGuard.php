<?php

namespace Omnishield\Policy;

/**
 * A challenge the site issues itself: nobody is called, nothing counted.
 *
 * @internal
 */
trait IssuesThroughGuard
{
    public function issue(?string $action = null): array
    {
        return $this->gateway->issue($action);
    }
}
