<?php

namespace Jovian\Toolkits\Appkit\UI;

use NSRect;
use NSLayoutConstraint;
use NSProgressIndicator;
use NSProgressIndicatorStyle;
use Surface\Toolkits\UI\TKProgressBar;
use Jovian\Toolkits\Appkit\UI\Concerns\AppkitNative;
use Jovian\Toolkits\Appkit\UI\Concerns\TakesInputFromAbove;

/**
 * An NSProgressIndicator bar. It has no natural width, so it asks for one just above the
 * stack's pull to zero.
 */
class AppkitProgressBar extends TKProgressBar
{
    use AppkitNative {
        releaseNative as private releaseView;
    }
    use TakesInputFromAbove;

    /** Points across when nothing stretches it. */
    private const float WIDTH = 120.0;

    protected function bootstrapNative(): void
    {
        $bar = NSProgressIndicator::initWithFrame(new NSRect(0.0, 0.0, 0.0, 0.0));
        $bar->setStyle(NSProgressIndicatorStyle::BAR);
        $bar->setMinValue(0.0);
        $bar->setMaxValue(1.0);
        $this->adoptNative($bar);

        $width = $bar->widthAnchor()->constraintGreaterThanOrEqualToConstant(self::WIDTH);
        $width->setPriority(490.0);
        NSLayoutConstraint::activateConstraints([$width]);

        $this->applyFraction($this->fraction());
    }

    protected function applyFraction(?float $fraction): void
    {
        $bar = $this->barNative();
        if (is_null($fraction)) {
            $bar->setIndeterminate(true);
            $bar->startAnimation(null);

            return;
        }

        $bar->stopAnimation(null);
        $bar->setIndeterminate(false);
        $bar->setDoubleValue($fraction);
    }

    /** It takes no input, so it has nothing to disable. */
    protected function applyNativeEnabled(bool $takes_input): void {}

    protected function releaseNative(): void
    {
        $this->barNative()->stopAnimation(null);
        $this->releaseView();
    }

    private function barNative(): NSProgressIndicator
    {
        /** @var NSProgressIndicator */
        return $this->native();
    }
}
