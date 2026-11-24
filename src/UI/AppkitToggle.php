<?php

namespace Jovian\Toolkits\Appkit\UI;

use NSRect;
use NSSwitch;
use ObjCTarget;
use WeakReference;
use NSControlStateValue;
use Surface\Toolkits\UI\TKToggle;
use Jovian\Toolkits\Appkit\UI\Concerns\AppkitNative;
use Jovian\Toolkits\Appkit\UI\Concerns\TakesInputFromAbove;

/**
 * NSSwitch. Its action fires on the user's flip only, and tells topside the new state.
 */
class AppkitToggle extends TKToggle
{
    use AppkitNative {
        releaseNative as private releaseView;
    }
    use TakesInputFromAbove;

    /**
     * The action's target. Controls hold their targets weakly: the primitive keeps it.
     */
    private ?ObjCTarget $target = null;

    protected function bootstrapNative(): void
    {
        $self = WeakReference::create($this);
        $this->target = new ObjCTarget(static function () use ($self): void {
            $switch = $self->get();
            $switch?->toggled($switch->switchNative()->state() === NSControlStateValue::ON);
        });

        $switch = NSSwitch::initWithFrame(new NSRect(0.0, 0.0, 0.0, 0.0));
        $switch->setState(self::state($this->isOn()));
        $switch->setTarget($this->target);
        $switch->setAction(ObjCTarget::ACTION);
        $this->adoptNative($switch);
    }

    protected function applyOn(bool $on): void
    {
        $this->switchNative()->setState(self::state($on));
    }

    protected function applyNativeEnabled(bool $takes_input): void
    {
        $this->switchNative()->setEnabled($takes_input);
    }

    protected function releaseNative(): void
    {
        $this->switchNative()->setTarget(null);
        $this->target = null;
        $this->releaseView();
    }

    private function switchNative(): NSSwitch
    {
        /** @var NSSwitch */
        return $this->native();
    }

    private static function state(bool $on): NSControlStateValue
    {
        return $on ? NSControlStateValue::ON : NSControlStateValue::OFF;
    }
}
