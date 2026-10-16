<?php

namespace Omnishield;

use Omnishield\Model\Widget;

/**
 * A widget's markup, printed by the page's printer when it reaches the page,
 * not before: a form's view built and thrown away (a view made to collect a
 * page's assets, a field taken out of the view), or built before another,
 * would otherwise take its provider's script with it, and the widget the
 * page shows would never run. Printed once, then the same markup however many
 * times it is read.
 *
 *     $view->vars['omnishield_html'] = $printer->later($widget, $nonce);   // {{ omnishield_html|raw }}
 *     $html = $html->wrap('<div hidden>', '</div>');                        // still printed when shown
 */
final class WidgetHtml implements \Stringable
{
    private ?string $html = null;

    public function __construct(
        private readonly WidgetPrinter $printer,
        public readonly Widget $widget,
        private readonly ?string $nonce = null,
        private readonly string $before = '',
        private readonly string $after = '',
    ) {
    }

    /** The same widget between two pieces of markup, printed when it is shown. */
    public function wrap(string $before, string $after): self
    {
        return new self($this->printer, $this->widget, $this->nonce, $before.$this->before, $this->after.$after);
    }

    public function __toString(): string
    {
        return $this->html ??= $this->before.$this->printer->print($this->widget, $this->nonce).$this->after;
    }
}
