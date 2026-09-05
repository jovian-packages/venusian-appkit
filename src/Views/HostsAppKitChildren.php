<?php

namespace Jovian\Venusian\AppKit\Views;

use Jovian\Bindings\AppKit\NS\NSView;

/**
 * A container twin whose native can hold children. The delegate's mints
 * addSubview onto childSurface() instead of the window content when a view
 * is conjured into the container.
 */
interface HostsAppKitChildren
{
    public function childSurface(): NSView;
}
