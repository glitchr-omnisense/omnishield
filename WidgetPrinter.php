<?php

namespace Omnishield;

use Omnishield\Model\Widget;

/**
 * Prints widgets for one page: a provider's script once, however many forms
 * the page holds - with the first widget printed, so a form's field asks for
 * its markup with later(), printed when the page shows it. One printer per
 * response - reset() between two, in a worker that serves many.
 */
final class WidgetPrinter
{
    /** @var array<string, true> the scripts already printed */
    private array $printed = [];

    public function print(Widget $widget, ?string $nonce = null): string
    {
        $first = null === $widget->script || !isset($this->printed[$widget->script]);
        if (null !== $widget->script) {
            $this->printed[$widget->script] = true;
        }

        return $widget->html($nonce, $first);
    }

    /** The widget's markup, printed when it is shown (cast to a string): a view built and not shown takes no script. */
    public function later(Widget $widget, ?string $nonce = null): WidgetHtml
    {
        return new WidgetHtml($this, $widget, $nonce);
    }

    public function reset(): void
    {
        $this->printed = [];
    }
}
