<?php

namespace Jovian\Toolkits\Appkit\Primitives;

use Jovian\Toolkits\Appkit\Primitives\Concerns\AppkitPrimitive;
use NSSlider;
use ObjCTarget;
use Surface\Contracts\Windows\Mail\View\ValueChanged;
use Surface\Contracts\Windows\Primitives\Placement;
use Surface\Contracts\Windows\ToolkitWindow;
use Surface\Windows\Primitives\TKPrimitiveGroup;
use Surface\Windows\Primitives\TKSlider;

/**
 * A continuous horizontal NSSlider; its action posts ValueChanged as the knob moves.
 */
class AppkitSlider extends TKSlider
{
    use AppkitPrimitive;

    /**
     * Controls hold targets weakly: the primitive keeps it.
     */
    protected ObjCTarget $target;

    public function __construct(string $name, ToolkitWindow $window, ?TKPrimitiveGroup $parent, Placement $placement, float $min, float $max, float $value)
    {
        parent::__construct($name, $window, $parent, $placement, $min, $max, $value);
        $this->target = new ObjCTarget(fn () => $this->moved());
        $slider = $this->adoptNative(NSSlider::sliderWithValueMinValueMaxValueTargetAction($this->value, $this->min, $this->max, $this->target, ObjCTarget::ACTION));
        $slider->setContinuous(true);
    }

    public function native(): NSSlider
    {
        return $this->native;
    }

    protected function applyValue(float $value): void
    {
        $this->native->setDoubleValue($value);
    }

    protected function applyRange(float $min, float $max): void
    {
        $this->native->setMinValue($min);
        $this->native->setMaxValue($max);
    }

    protected function releaseNative(): void
    {
        $this->native->setTarget(null);
    }

    protected function moved(): void
    {
        $this->nativeValueChanged($this->native->doubleValue());
        $this->session()->post(new ValueChanged($this->window->name(), $this->path(), $this->uuid, $this->value));
    }
}
