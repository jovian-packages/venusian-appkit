<?php

namespace Jovian\Venusian\AppKit\Views;

use Jovian\Bindings\AppKit\NS\NSControl;
use Jovian\Bindings\AppKit\NS\NSSlider;
use Jovian\Bindings\AppKit\Runtime\Bridge;
use Jovian\Bindings\AppKit\Runtime\ObjCObject;
use Surface\Contracts\NativeWindows\Views\Color;
use Surface\NativeWindows\Views\Slider;
use Surface\NativeWindows\Windowable;

/**
 * A Surface slider over an NSSlider set continuous, so the action streams
 * while the thumb drags — every step lands in fireChanged() with the
 * value AppKit holds.
 */
class AppKitSlider extends Slider
{
    use TranslatesAppKitFrames;

    public function __construct(
        string $name,
        Windowable $window,
        float $min,
        float $max,
        float $value,
        public readonly NSSlider $slider,
    ) {
        parent::__construct($name, $window, $min, $max, $value);

        Bridge::setAction(
            $slider->handle,
            fn (ObjCObject $sender) => $this->fireChanged($this->slider->doubleValue()),
        );
    }

    protected function control(): NSControl
    {
        return $this->slider;
    }

    protected function applyValue(float $value): void
    {
        $this->slider->setDoubleValue($value);
    }

    protected function applyRange(float $min, float $max): void
    {
        $this->slider->setMinValue($min);
        $this->slider->setMaxValue($max);
    }

    protected function applyEnabled(bool $enabled): void
    {
        $this->slider->setEnabled($enabled);
    }

    /**
     * A slider draws no fill of its own — AppKit has no honest path for
     * painting behind the track, so the colour is ignored.
     */
    protected function applyBackground(Color $color): void {}
}
