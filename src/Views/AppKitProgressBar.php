<?php

namespace Jovian\Venusian\AppKit\Views;

use Jovian\Bindings\AppKit\NS\NSProgressIndicator;
use Jovian\Bindings\AppKit\NS\NSView;
use Surface\Contracts\NativeWindows\Views\Color;
use Surface\NativeWindows\Views\ProgressBar;
use Surface\NativeWindows\Windowable;

/**
 * A Surface progress bar over a determinate NSProgressIndicator in the
 * bar style, ranged 0..1 to match Surface's promise.
 */
class AppKitProgressBar extends ProgressBar
{
    use TranslatesAppKitViewFrames;

    public function __construct(
        string $name,
        Windowable $window,
        float $progress,
        public readonly NSProgressIndicator $indicator,
    ) {
        parent::__construct($name, $window, $progress);
    }

    protected function nsView(): NSView
    {
        return $this->indicator;
    }

    protected function applyProgress(float $progress): void
    {
        $this->indicator->setDoubleValue($progress);
    }

    protected function measure(): array
    {
        $this->indicator->sizeToFit();
        $frame = $this->indicator->frame();

        return [(int) ceil($frame->width), (int) ceil($frame->height)];
    }

    /**
     * The bar draws no fill of its own — AppKit has no honest path for
     * painting behind the track, so the colour is ignored.
     */
    protected function applyBackground(Color $color): void {}
}
