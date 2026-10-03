<?php

namespace Jovian\Toolkits\Appkit\Primitives;

use Jovian\Toolkits\Appkit\Primitives\Concerns\AppkitPrimitive;
use NSProgressIndicator;
use NSProgressIndicatorStyle;
use NSRect;
use Surface\Contracts\Windows\Primitives\Placement;
use Surface\Contracts\Windows\ToolkitWindow;
use Surface\Windows\Primitives\TKPrimitiveGroup;
use Surface\Windows\Primitives\TKSpinner;

/**
 * A spinning NSProgressIndicator, shown only while it animates, as GtkSpinner draws nothing when stopped.
 */
class AppkitSpinner extends TKSpinner
{
    use AppkitPrimitive;

    public function __construct(string $name, ToolkitWindow $window, ?TKPrimitiveGroup $parent, Placement $placement)
    {
        parent::__construct($name, $window, $parent, $placement);
        $indicator = $this->adoptNative(NSProgressIndicator::initWithFrame(new NSRect()));
        $indicator->setStyle(NSProgressIndicatorStyle::SPINNING);
        $indicator->setIndeterminate(true);
        $indicator->setDisplayedWhenStopped(false);
    }

    public function native(): NSProgressIndicator
    {
        return $this->native;
    }

    protected function applySpinning(bool $spinning): void
    {
        $spinning ? $this->native->startAnimation(null) : $this->native->stopAnimation(null);
    }

    protected function releaseNative(): void
    {
        $this->native->stopAnimation(null);
    }
}
