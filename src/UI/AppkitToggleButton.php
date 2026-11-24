<?php

namespace Jovian\Toolkits\Appkit\UI;

use NSButton;
use ObjCTarget;
use NSButtonType;
use WeakReference;
use NSControlStateValue;
use Surface\Toolkits\UI\TKToggleButton;
use Jovian\Toolkits\Appkit\UI\Concerns\AppkitNative;
use Jovian\Toolkits\Appkit\UI\Concerns\TakesInputFromAbove;

/**
 * A push-on, push-off NSButton. Its action fires on the user's press only.
 */
class AppkitToggleButton extends TKToggleButton
{
    use AppkitNative {
        releaseNative as private releaseView;
    }
    use TakesInputFromAbove;

    /** Controls hold their targets weakly: the primitive keeps it. */
    private ?ObjCTarget $target = null;

    protected function bootstrapNative(): void
    {
        $self = WeakReference::create($this);
        $this->target = new ObjCTarget(static function () use ($self): void {
            $button = $self->get();
            $button?->toggled($button->buttonNative()->state() === NSControlStateValue::ON);
        });

        $button = NSButton::buttonWithTitleTargetAction($this->label(), $this->target, ObjCTarget::ACTION);
        $button->setButtonType(NSButtonType::PUSH_ON_PUSH_OFF);
        $this->adoptNative($button);
        $this->applyPressed($this->isPressed());
    }

    protected function applyLabel(string $label): void
    {
        $this->buttonNative()->setTitle($label);
    }

    protected function applyPressed(bool $pressed): void
    {
        $this->buttonNative()->setState($pressed ? NSControlStateValue::ON : NSControlStateValue::OFF);
    }

    protected function applyNativeEnabled(bool $takes_input): void
    {
        $this->buttonNative()->setEnabled($takes_input);
    }

    protected function releaseNative(): void
    {
        $this->buttonNative()->setTarget(null);
        $this->target = null;
        $this->releaseView();
    }

    private function buttonNative(): NSButton
    {
        /** @var NSButton */
        return $this->native();
    }
}
