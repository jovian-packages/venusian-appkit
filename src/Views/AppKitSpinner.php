<?php

namespace Jovian\Venusian\AppKit\Views;

use Jovian\Bindings\AppKit\NS\NSProgressIndicator;
use Jovian\Bindings\AppKit\Values\NSRect;
use Surface\Contracts\NativeWindows\Views\Color;
use Surface\NativeWindows\Views\Spinner;
use Surface\NativeWindows\Windowable;

/**
 * A Surface spinner over an indeterminate NSProgressIndicator in the
 * spinning style.
 *
 * NSProgressIndicator is a plain NSView, not an NSControl, so this view
 * cannot share TranslatesAppKitFrames — it pays the top-left inversion
 * itself with the same arithmetic.
 */
class AppKitSpinner extends Spinner
{
    public function __construct(
        string $name,
        Windowable $window,
        public readonly NSProgressIndicator $indicator,
    ) {
        parent::__construct($name, $window);
    }

    protected function applySpinning(bool $spinning): void
    {
        if ($spinning) {
            $this->indicator->startAnimation(0);
        } else {
            $this->indicator->stopAnimation(0);
        }
    }

    protected function applyFrame(int $x, int $y, int $width, int $height): void
    {
        [, $content_height] = $this->window->contentSize();

        $this->indicator->setFrame(new NSRect(
            (float) $x,
            (float) ($content_height - $y - $height),
            (float) $width,
            (float) $height,
        ));
    }

    protected function measure(): array
    {
        $this->indicator->sizeToFit();
        $frame = $this->indicator->frame();

        return [(int) ceil($frame->width), (int) ceil($frame->height)];
    }

    protected function destroyNative(): void
    {
        $this->indicator->removeFromSuperview();
    }

    /**
     * A spinner draws no fill of its own — AppKit has no honest path for
     * painting one behind the indicator glyph, so the colour is ignored.
     */
    protected function applyBackground(Color $color): void {}
}
