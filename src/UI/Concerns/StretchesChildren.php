<?php

namespace Jovian\Toolkits\Appkit\UI\Concerns;

use NSView;
use NSLayoutConstraintOrientation;

/**
 * For a container that gives a child all its room (a scroll view, a page, a pane): the child
 * hugs low, so it grows.
 */
trait StretchesChildren
{
    private static function stretch(NSView $view): void
    {
        foreach ([NSLayoutConstraintOrientation::HORIZONTAL, NSLayoutConstraintOrientation::VERTICAL] as $orientation) {
            $view->setContentHuggingPriorityForOrientation(1.0, $orientation);
        }
    }
}
