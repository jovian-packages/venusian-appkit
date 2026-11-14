<?php

namespace Jovian\Toolkits\Appkit\Primitives;

use Jovian\Toolkits\Appkit\Primitives\Concerns\AppkitPrimitive;
use Jovian\Toolkits\Appkit\Primitives\Concerns\AppkitStack;
use Surface\Contracts\Toolkits\Primitives\Placement;
use Surface\Contracts\Toolkits\ToolkitWindow;
use Surface\Toolkits\Primitives\TKColumn;
use Surface\Toolkits\Primitives\TKPrimitiveGroup;

/**
 * A vertical NSStackView: children in order, spare space to the ones that fill.
 */
class AppkitColumn extends TKColumn
{
    use AppkitPrimitive;
    use AppkitStack;

    public function __construct(string $name, ToolkitWindow $window, ?TKPrimitiveGroup $parent, Placement $placement, int $spacing, int $padding)
    {
        parent::__construct($name, $window, $parent, $placement, $spacing, $padding);
        $this->buildStack($spacing, $padding);
    }

    protected function isVertical(): bool
    {
        return true;
    }
}
