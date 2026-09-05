<?php

namespace Jovian\Venusian\AppKit\Views;

use Jovian\Bindings\AppKit\NS\NSView;
use Jovian\Bindings\AppKit\QuartzCore\CALayer;
use Surface\Contracts\NativeWindows\Views\Color;
use Surface\NativeWindows\Views\Group;
use Surface\NativeWindows\Windowable;

/**
 * A Surface group over a plain NSView. Children the delegate mints into
 * childSurface() are real subviews, so moving the group moves the subtree
 * natively; their frames invert against this group's inner height through
 * the shared layoutSpace() route.
 *
 * The layer clips the subtree once a background forces one; a bare group
 * follows AppKit's default and does not clip.
 */
class AppKitGroup extends Group implements HostsAppKitChildren
{
    use ComposesAppKitStyle;
    use TranslatesAppKitViewFrames;

    public function __construct(
        string $name,
        Windowable $window,
        public readonly NSView $view,
    ) {
        parent::__construct($name, $window);
    }

    public function childSurface(): NSView
    {
        return $this->view;
    }

    protected function nsView(): NSView
    {
        return $this->view;
    }

    protected function applyBackground(Color $color): void
    {
        $this->view->setWantsLayer(true);
        $layer = $this->view->layer();
        if ($layer instanceof CALayer) {
            $ns_color = $this->nsColor($color);
            $layer->setBackgroundColor($ns_color->CGColor());
        }
    }
}
