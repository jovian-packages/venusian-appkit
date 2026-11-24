<?php

namespace Jovian\Toolkits\Appkit\UI\Concerns;

use Surface\Toolkits\UI\TKPrimitive;

/**
 * For an AppKit container.
 *
 * Its native is a plain view (NSView, NSStackView), which has no enabled state and does
 * not reach the controls inside it. So the container carries its enabled flag down itself:
 * when it changes, and when a child is added while it, or a container above it, is disabled.
 */
trait CarriesEnabledDown
{
    /**
     * Each child works out whether it takes input again. A child disabled itself stays as
     * it is, and so does everything inside it.
     */
    protected function applyEnabled(bool $enabled): void
    {
        foreach ($this->children() as $child) {
            if ($child->isEnabled()) {
                $child->applyEnabled(true);
            }
        }
    }

    /**
     * A child added while this container, or one above it, is disabled starts out taking
     * no input.
     */
    protected function adopt(TKPrimitive $child): void
    {
        parent::adopt($child);

        if ($child->isEnabled() && ! $child->takesInput()) {
            $child->applyEnabled(true);
        }
    }
}
