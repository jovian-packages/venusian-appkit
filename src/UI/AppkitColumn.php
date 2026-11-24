<?php

namespace Jovian\Toolkits\Appkit\UI;

use Surface\Toolkits\UI\TKColumn;
use Jovian\Toolkits\Appkit\UI\Concerns\AppkitStackChildren;

/**
 * A vertical NSStackView: children one under another.
 */
class AppkitColumn extends TKColumn
{
    use AppkitStackChildren;

    protected function isVertical(): bool
    {
        return true;
    }
}
