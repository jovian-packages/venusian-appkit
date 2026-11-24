<?php

namespace Jovian\Toolkits\Appkit\UI;

use NSButton;
use ObjCTarget;
use WeakReference;
use NSControlStateValue;
use Surface\Toolkits\UI\TKCheckbox;
use Jovian\Toolkits\Appkit\UI\Concerns\AppkitNative;
use Jovian\Toolkits\Appkit\UI\Concerns\TakesInputFromAbove;

/**
 * NSButton::checkboxWithTitleTargetAction. Its action fires on the user's toggle only, and
 * tells topside the new state. A disabled check box sends no action.
 */
class AppkitCheckbox extends TKCheckbox
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
            $box = $self->get();
            $box?->toggled($box->boxNative()->state() === NSControlStateValue::ON);
        });

        $box = NSButton::checkboxWithTitleTargetAction($this->label(), $this->target, ObjCTarget::ACTION);
        $box->setState(self::state($this->isChecked()));
        $this->adoptNative($box);
    }

    protected function applyLabel(string $label): void
    {
        $this->boxNative()->setTitle($label);
    }

    protected function applyChecked(bool $checked): void
    {
        $this->boxNative()->setState(self::state($checked));
    }

    protected function applyNativeEnabled(bool $takes_input): void
    {
        $this->boxNative()->setEnabled($takes_input);
    }

    protected function releaseNative(): void
    {
        $this->boxNative()->setTarget(null);
        $this->target = null;
        $this->releaseView();
    }

    private function boxNative(): NSButton
    {
        /** @var NSButton */
        return $this->native();
    }

    private static function state(bool $on): NSControlStateValue
    {
        return $on ? NSControlStateValue::ON : NSControlStateValue::OFF;
    }
}
