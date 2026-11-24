<?php

namespace Jovian\Toolkits\Appkit\UI;

use NSRect;
use NSProgressIndicator;
use NSProgressIndicatorStyle;
use Surface\Toolkits\UI\TKSpinner;
use Jovian\Toolkits\Appkit\UI\Concerns\AppkitNative;
use Jovian\Toolkits\Appkit\UI\Concerns\TakesInputFromAbove;

/**
 * A spinning NSProgressIndicator. Stopped, it keeps its place and draws nothing.
 */
class AppkitSpinner extends TKSpinner
{
    use AppkitNative {
        releaseNative as private releaseView;
    }
    use TakesInputFromAbove;

    protected function bootstrapNative(): void
    {
        $spinner = NSProgressIndicator::initWithFrame(new NSRect(0.0, 0.0, 0.0, 0.0));
        $spinner->setStyle(NSProgressIndicatorStyle::SPINNING);
        $spinner->setIndeterminate(true);
        $spinner->setDisplayedWhenStopped(false);
        $this->adoptNative($spinner);
        $this->applySpinning($this->isSpinning());
    }

    protected function applySpinning(bool $spinning): void
    {
        $spinning ? $this->spinnerNative()->startAnimation(null) : $this->spinnerNative()->stopAnimation(null);
    }

    /** It takes no input, so it has nothing to disable. */
    protected function applyNativeEnabled(bool $takes_input): void {}

    protected function releaseNative(): void
    {
        $this->spinnerNative()->stopAnimation(null);
        $this->releaseView();
    }

    private function spinnerNative(): NSProgressIndicator
    {
        /** @var NSProgressIndicator */
        return $this->native();
    }
}
