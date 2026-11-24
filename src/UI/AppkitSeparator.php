<?php

namespace Jovian\Toolkits\Appkit\UI;

use NSBox;
use NSRect;
use NSBoxType;
use NSLayoutConstraint;
use Surface\Toolkits\UI\TKSeparator;
use Jovian\Toolkits\Appkit\UI\Concerns\AppkitNative;
use Jovian\Toolkits\Appkit\UI\Concerns\TakesInputFromAbove;

/**
 * An NSBox separator, one point thick.
 */
class AppkitSeparator extends TKSeparator
{
    use AppkitNative;
    use TakesInputFromAbove;

    protected function bootstrapNative(): void
    {
        $across = $this->isHorizontal();
        $box = NSBox::initWithFrame($across ? new NSRect(0.0, 0.0, 100.0, 1.0) : new NSRect(0.0, 0.0, 1.0, 100.0));
        $box->setBoxType(NSBoxType::SEPARATOR);
        $this->adoptNative($box);

        NSLayoutConstraint::activateConstraints([
            ($across ? $box->heightAnchor() : $box->widthAnchor())->constraintEqualToConstant(1.0),
        ]);
    }

    /** It takes no input, so it has nothing to disable. */
    protected function applyNativeEnabled(bool $takes_input): void {}
}
