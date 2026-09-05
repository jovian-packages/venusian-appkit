<?php

namespace Jovian\Venusian\AppKit\Views;

use Jovian\Bindings\AppKit\NS\NSBox;
use Jovian\Bindings\AppKit\NS\NSView;
use Surface\Contracts\NativeWindows\Views\Color;
use Surface\NativeWindows\Views\Separator;
use Surface\NativeWindows\Windowable;

/**
 * A Surface separator over an NSBox in the separator type. AppKit orients
 * the line from the frame's aspect on its own — the same rule Surface
 * fixed at conjure time.
 */
class AppKitSeparator extends Separator
{
    use TranslatesAppKitViewFrames;

    public function __construct(
        string $name,
        Windowable $window,
        bool $horizontal,
        public readonly NSBox $box,
    ) {
        parent::__construct($name, $window, $horizontal);
    }

    protected function nsView(): NSView
    {
        return $this->box;
    }

    /** A separator's natural size is a hairline along its axis. */
    protected function measure(): array
    {
        return $this->horizontal ? [$this->width, 1] : [1, $this->height];
    }

    /**
     * The line's colour is the system's — AppKit has no honest per-box
     * separator tint, so the colour is ignored.
     */
    protected function applyBackground(Color $color): void {}
}
