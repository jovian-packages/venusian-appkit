<?php

namespace Jovian\Toolkits\Appkit\Primitives;

use Jovian\Toolkits\Appkit\Primitives\Concerns\AppkitPrimitive;
use NSButton;
use NSControlStateValue;
use ObjCTarget;
use Surface\Contracts\Windows\Mail\View\Toggled;
use Surface\Contracts\Windows\Primitives\Placement;
use Surface\Contracts\Windows\ToolkitWindow;
use Surface\Windows\Primitives\TKCheckbox;
use Surface\Windows\Primitives\TKPrimitiveGroup;

/**
 * A checkbox NSButton; its action posts Toggled with the new state.
 */
class AppkitCheckbox extends TKCheckbox
{
    use AppkitPrimitive;

    /**
     * Controls hold targets weakly: the primitive keeps it.
     */
    protected ObjCTarget $target;

    public function __construct(string $name, ToolkitWindow $window, ?TKPrimitiveGroup $parent, Placement $placement, string $label, bool $checked)
    {
        parent::__construct($name, $window, $parent, $placement, $label, $checked);
        $this->target = new ObjCTarget(fn () => $this->toggled());
        $box = $this->adoptNative(NSButton::checkboxWithTitleTargetAction($label, $this->target, ObjCTarget::ACTION));
        $box->setState($checked ? NSControlStateValue::ON : NSControlStateValue::OFF);
    }

    public function native(): NSButton
    {
        return $this->native;
    }

    protected function applyLabel(string $label): void
    {
        $this->native->setTitle($label);
    }

    protected function applyChecked(bool $checked): void
    {
        $this->native->setState($checked ? NSControlStateValue::ON : NSControlStateValue::OFF);
    }

    protected function releaseNative(): void
    {
        $this->native->setTarget(null);
    }

    protected function toggled(): void
    {
        $this->nativeToggled($this->native->state() === NSControlStateValue::ON);
        $this->session()->post(new Toggled($this->window->name(), $this->path(), $this->uuid, $this->checked));
    }
}
