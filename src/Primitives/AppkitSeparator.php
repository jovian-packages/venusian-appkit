<?php

namespace Jovian\Toolkits\Appkit\Primitives;

use Jovian\Toolkits\Appkit\Primitives\Concerns\AppkitPrimitive;
use NSBox;
use NSBoxType;
use NSLayoutConstraint;
use NSRect;
use Surface\Contracts\Windows\Primitives\Placement;
use Surface\Contracts\Windows\ToolkitWindow;
use Surface\Windows\Primitives\TKPrimitiveGroup;
use Surface\Windows\Primitives\TKSeparator;

/**
 * An NSBox separator. AppKit reads a separator's direction from its frame, so it is built long
 * in its own direction, and held one point thick across it.
 */
class AppkitSeparator extends TKSeparator
{
    use AppkitPrimitive;

    public function __construct(string $name, ToolkitWindow $window, ?TKPrimitiveGroup $parent, Placement $placement, bool $horizontal)
    {
        parent::__construct($name, $window, $parent, $placement, $horizontal);
        $box = $this->adoptNative(NSBox::initWithFrame($horizontal ? new NSRect(0.0, 0.0, 100.0, 1.0) : new NSRect(0.0, 0.0, 1.0, 100.0)));
        $box->setBoxType(NSBoxType::SEPARATOR);
        NSLayoutConstraint::activateConstraints([
            ($horizontal ? $box->heightAnchor() : $box->widthAnchor())->constraintEqualToConstant(1.0),
        ]);
    }

    public function native(): NSBox
    {
        return $this->native;
    }
}
