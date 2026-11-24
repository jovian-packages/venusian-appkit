<?php

namespace Jovian\Toolkits\Appkit\UI;

use NSButton;
use ObjCTarget;
use WeakReference;
use Surface\Toolkits\UI\TKButton;
use Jovian\Toolkits\Appkit\UI\Concerns\AppkitNative;
use Jovian\Toolkits\Appkit\UI\Concerns\TakesInputFromAbove;

/**
 * A push button whose action posts ButtonClicked. A disabled button sends no action.
 *
 * Keyboard focus follows macOS: focus() makes it first responder, and Tab reaches it only
 * while the user has Full Keyboard Access on.
 */
class AppkitButton extends TKButton
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
        // The target holds its callable where PHP's collector cannot see it: a weak reference
        // keeps it from holding the primitive.
        $self = WeakReference::create($this);
        $this->target = new ObjCTarget(static function () use ($self): void {
            $self->get()?->clicked();
        });
        $this->adoptNative(NSButton::buttonWithTitleTargetAction($this->label(), $this->target, ObjCTarget::ACTION));
    }

    protected function applyLabel(string $label): void
    {
        $this->buttonNative()->setTitle($label);
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
