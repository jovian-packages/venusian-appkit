<?php

namespace Jovian\Toolkits\Appkit\UI;

use Surface\Toolkits\UI\TKRow;
use Jovian\Toolkits\Appkit\UI\Concerns\AppkitStackChildren;

/**
 * A horizontal NSStackView: children side by side.
 */
class AppkitRow extends TKRow
{
    use AppkitStackChildren;

    protected function isVertical(): bool
    {
        return false;
    }
}
