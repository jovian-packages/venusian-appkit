<?php

namespace Jovian\Toolkits\Appkit\UI\Concerns;

use NSView;
use NSScrollView;
use NSTextView;
use NSLayoutConstraint;

/**
 * For a primitive that is a view scrolled inside an NSScrollView (a table, a text area): the
 * focus is the inner view's, and the scroll view, which has no natural size, asks for a
 * little just above a stack's pull to zero.
 */
trait ScrollsInside
{
    /** The view inside, which takes the focus. */
    abstract protected function focusTarget(): NSView;

    public function hasFocus(): bool
    {
        if (is_null($this->native)) {
            return false;
        }

        $inner = $this->focusTarget();
        $responder = $inner->window()?->firstResponder();

        return $responder === $inner || ($responder instanceof NSTextView && $responder->delegate() === $inner);
    }

    protected function nativeFocus(): bool
    {
        $inner = $this->focusTarget();
        $window = $inner->window();
        if (is_null($window) || ! $inner->acceptsFirstResponder()) {
            return false;
        }

        $window->makeFirstResponder($inner);

        return $this->hasFocus();
    }

    private static function leastSize(NSScrollView $scroll): void
    {
        $least = [
            $scroll->widthAnchor()->constraintGreaterThanOrEqualToConstant(160.0),
            $scroll->heightAnchor()->constraintGreaterThanOrEqualToConstant(80.0),
        ];
        foreach ($least as $constraint) {
            // Over a stack's pull to zero (480), under a split's divider (489).
            $constraint->setPriority(485.0);
        }
        NSLayoutConstraint::activateConstraints($least);
    }
}
