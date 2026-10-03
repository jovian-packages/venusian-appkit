<?php

namespace Jovian\Toolkits\Appkit\Primitives;

use Jovian\Toolkits\Appkit\Primitives\Concerns\AppkitPrimitive;
use NSControlStateValue;
use NSRect;
use NSSwitch;
use ObjCTarget;
use Surface\Contracts\Windows\Mail\View\Toggled;
use Surface\Contracts\Windows\Primitives\Placement;
use Surface\Contracts\Windows\ToolkitWindow;
use Surface\Windows\Primitives\TKPrimitiveGroup;
use Surface\Windows\Primitives\TKToggle;

/**
 * An NSSwitch; its action posts Toggled with the new state.
 */
class AppkitToggle extends TKToggle
{
    use AppkitPrimitive;

    /**
     * Controls hold targets weakly: the primitive keeps it.
     */
    protected ObjCTarget $target;

    public function __construct(string $name, ToolkitWindow $window, ?TKPrimitiveGroup $parent, Placement $placement, bool $on)
    {
        parent::__construct($name, $window, $parent, $placement, $on);
        $this->target = new ObjCTarget(fn () => $this->toggled());
        $switch = $this->adoptNative(NSSwitch::initWithFrame(new NSRect()));
        $switch->setState($on ? NSControlStateValue::ON : NSControlStateValue::OFF);
        $switch->setTarget($this->target);
        $switch->setAction(ObjCTarget::ACTION);
    }

    public function native(): NSSwitch
    {
        return $this->native;
    }

    protected function applyOn(bool $on): void
    {
        $this->native->setState($on ? NSControlStateValue::ON : NSControlStateValue::OFF);
    }

    protected function releaseNative(): void
    {
        $this->native->setTarget(null);
    }

    protected function toggled(): void
    {
        $this->nativeToggled($this->native->state() === NSControlStateValue::ON);
        $this->session()->post(new Toggled($this->window->name(), $this->path(), $this->uuid, $this->on));
    }
}
