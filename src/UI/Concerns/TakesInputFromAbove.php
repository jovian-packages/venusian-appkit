<?php

namespace Jovian\Toolkits\Appkit\UI\Concerns;

/**
 * For an AppKit primitive that is not a container.
 *
 * AppKit has no enabled state on a view, only on a control (NSControl), so a disabled
 * container cannot pass it down: each primitive works out whether it takes input from its
 * own flag and every container above it, and sets that on its native.
 */
trait TakesInputFromAbove
{
    /**
     * Called with this primitive's own flag; what the native gets also counts the
     * containers above it.
     */
    protected function applyEnabled(bool $enabled): void
    {
        $this->applyNativeEnabled($this->takesInput());
    }

    /**
     * Whether the native takes input: false when this primitive or a container above it is
     * disabled. A control sets it (NSControl::setEnabled); a native with no enabled state
     * of its own leaves it.
     */
    abstract protected function applyNativeEnabled(bool $takes_input): void;
}
