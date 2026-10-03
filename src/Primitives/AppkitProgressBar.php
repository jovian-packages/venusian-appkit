<?php

namespace Jovian\Toolkits\Appkit\Primitives;

use Jovian\Toolkits\Appkit\Primitives\Concerns\AppkitPrimitive;
use NSProgressIndicator;
use NSProgressIndicatorStyle;
use NSRect;
use Surface\Contracts\Windows\Primitives\Placement;
use Surface\Contracts\Windows\ToolkitWindow;
use Surface\Windows\Primitives\TKPrimitiveGroup;
use Surface\Windows\Primitives\TKProgressBar;

/**
 * A bar NSProgressIndicator over 0..1; a null fraction is the indeterminate, animating bar.
 */
class AppkitProgressBar extends TKProgressBar
{
    use AppkitPrimitive;

    public function __construct(string $name, ToolkitWindow $window, ?TKPrimitiveGroup $parent, Placement $placement, ?float $fraction)
    {
        parent::__construct($name, $window, $parent, $placement, $fraction);
        $bar = $this->adoptNative(NSProgressIndicator::initWithFrame(new NSRect()));
        $bar->setStyle(NSProgressIndicatorStyle::BAR);
        $bar->setMinValue(0.0);
        $bar->setMaxValue(1.0);
        $this->applyFraction($fraction);
    }

    public function native(): NSProgressIndicator
    {
        return $this->native;
    }

    protected function applyFraction(?float $fraction): void
    {
        $bar = $this->native;
        if (is_null($fraction)) {
            $bar->setIndeterminate(true);
            $bar->startAnimation(null);

            return;
        }

        $bar->stopAnimation(null);
        $bar->setIndeterminate(false);
        $bar->setDoubleValue($fraction);
    }

    protected function releaseNative(): void
    {
        $this->native->stopAnimation(null);
    }
}
