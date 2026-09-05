<?php

namespace Jovian\Venusian\AppKit\Views;

use Jovian\Bindings\AppKit\NS\NSView;
use Jovian\Bindings\AppKit\Values\NSRect;

/**
 * Frame mechanics for twins fronting a plain NSView rather than an
 * NSControl: the same top-left inversion as TranslatesAppKitFrames, but
 * measure() answers the current frame — a bare view has no sizeToFit.
 */
trait TranslatesAppKitViewFrames
{
    /** The native view this twin fronts. */
    abstract protected function nsView(): NSView;

    protected function applyFrame(int $x, int $y, int $width, int $height): void
    {
        [, $content_height] = $this->layoutSpace();

        $this->nsView()->setFrame(new NSRect(
            (float) $x,
            (float) ($content_height - $y - $height),
            (float) $width,
            (float) $height,
        ));
    }

    protected function measure(): array
    {
        $frame = $this->nsView()->frame();

        return [(int) ceil($frame->width), (int) ceil($frame->height)];
    }

    protected function destroyNative(): void
    {
        $this->nsView()->removeFromSuperview();
    }

    protected function applyVisible(bool $visible): void
    {
        $this->nsView()->setHidden(! $visible);
    }
}
