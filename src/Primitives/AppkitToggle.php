<?php

namespace Jovian\Toolkits\Appkit\Primitives;

use Jovian\Toolkits\Appkit\Primitives\Concerns\AppkitPrimitive;
use NSControlStateValue;
use NSRect;
use NSSwitch;
use ObjCTarget;
use Surface\Contracts\Toolkits\Mail\Views\Toggled;
use Surface\Contracts\Toolkits\Primitives\Placement;
use Surface\Contracts\Toolkits\ToolkitWindow;
use Surface\Toolkits\Primitives\TKPrimitiveGroup;
use Surface\Toolkits\Primitives\TKToggle;
use WeakReference;

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
        // The target holds its callable where PHP's collector cannot see it: a weak reference keeps it from holding the primitive.
        $self = WeakReference::create($this);
        $this->target = new ObjCTarget(static fn () => $self->get()?->toggled());
        $switch = $this->adoptNative(NSSwitch::initWithFrame(new NSRect(0.0, 0.0, 0.0, 0.0)));
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
