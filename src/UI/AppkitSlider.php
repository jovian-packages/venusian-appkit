<?php

namespace Jovian\Toolkits\Appkit\UI;

use NSSlider;
use ObjCTarget;
use WeakReference;
use NSLayoutConstraint;
use Surface\Toolkits\UI\TKSlider;
use Jovian\Toolkits\Appkit\UI\Concerns\AppkitNative;
use Jovian\Toolkits\Appkit\UI\Concerns\TakesInputFromAbove;

/**
 * A continuous NSSlider: its action fires as the user drags. It has no natural width, so it
 * asks for one just above the stack's pull to zero.
 */
class AppkitSlider extends TKSlider
{
    use AppkitNative {
        releaseNative as private releaseView;
    }
    use TakesInputFromAbove;

    /** Points across when nothing stretches it. */
    private const float WIDTH = 120.0;

    /** Controls hold their targets weakly: the primitive keeps it. */
    private ?ObjCTarget $target = null;

    protected function bootstrapNative(): void
    {
        $self = WeakReference::create($this);
        $this->target = new ObjCTarget(static function () use ($self): void {
            $slider = $self->get();
            $slider?->slid($slider->sliderNative()->doubleValue());
        });

        $slider = NSSlider::sliderWithValueMinValueMaxValueTargetAction($this->value(), $this->min(), $this->max(), $this->target, ObjCTarget::ACTION);
        $slider->setContinuous(true);
        $this->adoptNative($slider);

        $width = $slider->widthAnchor()->constraintGreaterThanOrEqualToConstant(self::WIDTH);
        $width->setPriority(490.0);
        NSLayoutConstraint::activateConstraints([$width]);
    }

    protected function applyValue(float $value): void
    {
        $this->sliderNative()->setDoubleValue($value);
    }

    protected function applyRange(float $min, float $max, float $value): void
    {
        $slider = $this->sliderNative();
        $slider->setMinValue($min);
        $slider->setMaxValue($max);
        $slider->setDoubleValue($value);
    }

    protected function applyNativeEnabled(bool $takes_input): void
    {
        $this->sliderNative()->setEnabled($takes_input);
    }

    protected function releaseNative(): void
    {
        $this->sliderNative()->setTarget(null);
        $this->target = null;
        $this->releaseView();
    }

    private function sliderNative(): NSSlider
    {
        /** @var NSSlider */
        return $this->native();
    }
}
