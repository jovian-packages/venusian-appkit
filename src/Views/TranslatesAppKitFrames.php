<?php

namespace Jovian\Venusian\AppKit\Views;

use Jovian\Bindings\AppKit\NS\NSControl;
use Jovian\Bindings\AppKit\Values\NSRect;

/**
 * Shared frame mechanics for AppKit-backed controls: the top-left to
 * bottom-left inversion, sizeToFit measurement, and terminal removal.
 * The one place every AppKit view pays the coordinate debt.
 */
trait TranslatesAppKitFrames
{
    /** The native control this view fronts. */
    abstract protected function control(): NSControl;

    protected function applyFrame(int $x, int $y, int $width, int $height): void
    {
        // Invert against the space the view lives in — the window content,
        // or the hosting group's inner size when conjured into one.
        [, $content_height] = $this->layoutSpace();

        $this->control()->setFrame(new NSRect(
            (float) $x,
            (float) ($content_height - $y - $height),
            (float) $width,
            (float) $height,
        ));
    }

    protected function measure(): array
    {
        $this->control()->sizeToFit();
        $frame = $this->control()->frame();

        return [(int) ceil($frame->width), (int) ceil($frame->height)];
    }

    protected function destroyNative(): void
    {
        $this->control()->removeFromSuperview();
    }

    protected function applyVisible(bool $visible): void
    {
        $this->control()->setHidden(! $visible);
    }
}
