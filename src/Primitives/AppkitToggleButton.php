<?php

namespace Jovian\Toolkits\Appkit\Primitives;

use Jovian\Toolkits\Appkit\Primitives\Concerns\AppkitPrimitive;
use NSButton;
use NSButtonType;
use NSControlStateValue;
use ObjCTarget;
use Surface\Contracts\Windows\Mail\View\Toggled;
use Surface\Contracts\Windows\Primitives\Placement;
use Surface\Contracts\Windows\ToolkitWindow;
use Surface\Windows\Primitives\TKPrimitiveGroup;
use Surface\Windows\Primitives\TKToggleButton;

/**
 * A push-on/push-off NSButton: pressed is its ON state; its action posts Toggled.
 */
class AppkitToggleButton extends TKToggleButton
{
    use AppkitPrimitive;

    /**
     * Controls hold targets weakly: the primitive keeps it.
     */
    protected ObjCTarget $target;

    public function __construct(string $name, ToolkitWindow $window, ?TKPrimitiveGroup $parent, Placement $placement, string $label, bool $pressed)
    {
        parent::__construct($name, $window, $parent, $placement, $label, $pressed);
        $this->target = new ObjCTarget(fn () => $this->toggled());
        $button = $this->adoptNative(NSButton::buttonWithTitleTargetAction($label, $this->target, ObjCTarget::ACTION));
        $button->setButtonType(NSButtonType::PUSH_ON_PUSH_OFF);
        $button->setState($pressed ? NSControlStateValue::ON : NSControlStateValue::OFF);
    }

    public function native(): NSButton
    {
        return $this->native;
    }

    protected function applyLabel(string $label): void
    {
        $this->native->setTitle($label);
    }

    protected function applyPressed(bool $pressed): void
    {
        $this->native->setState($pressed ? NSControlStateValue::ON : NSControlStateValue::OFF);
    }

    protected function releaseNative(): void
    {
        $this->native->setTarget(null);
    }

    protected function toggled(): void
    {
        $this->nativeToggled($this->native->state() === NSControlStateValue::ON);
        $this->session()->post(new Toggled($this->window->name(), $this->path(), $this->uuid, $this->pressed));
    }
}
