<?php

namespace Jovian\Venusian\AppKit\Views;

use Jovian\Bindings\AppKit\NS\NSControl;
use Jovian\Bindings\AppKit\NS\NSSwitch;
use Jovian\Bindings\AppKit\Runtime\Bridge;
use Jovian\Bindings\AppKit\Runtime\ObjCObject;
use Surface\Contracts\NativeWindows\Views\Color;
use Surface\NativeWindows\Views\Toggle;
use Surface\NativeWindows\Windowable;

/**
 * A Surface toggle over an NSSwitch. The flip lands in fireToggled() with
 * the state AppKit holds.
 */
class AppKitToggle extends Toggle
{
    use TranslatesAppKitFrames;

    public function __construct(
        string $name,
        Windowable $window,
        bool $on,
        public readonly NSSwitch $switch,
    ) {
        parent::__construct($name, $window, $on);

        Bridge::setAction(
            $switch->handle,
            fn (ObjCObject $sender) => $this->fireToggled($this->switch->state() === 1),
        );
    }

    protected function control(): NSControl
    {
        return $this->switch;
    }

    protected function applyOn(bool $on): void
    {
        $this->switch->setState($on ? 1 : 0);
    }

    protected function applyEnabled(bool $enabled): void
    {
        $this->switch->setEnabled($enabled);
    }

    /**
     * A switch draws no fill of its own — the colour is ignored, AppKit
     * owns the track's look.
     */
    protected function applyBackground(Color $color): void {}
}
