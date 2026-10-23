<?php

namespace Jovian\Toolkits\Appkit\Primitives;

use Jovian\Toolkits\Appkit\Primitives\Concerns\AppkitPrimitive;
use NSButton;
use ObjCTarget;
use Surface\Contracts\Windows\Mail\View\ButtonClicked;
use Surface\Contracts\Windows\Primitives\ButtonRole;
use Surface\Contracts\Windows\Primitives\Placement;
use Surface\Contracts\Windows\Styling\FontSpec;
use Surface\Contracts\Windows\ToolkitWindow;
use Surface\NutsAndBolts\Color;
use Surface\Windows\Primitives\TKButton;
use Surface\Windows\Primitives\TKPrimitiveGroup;
use WeakReference;

/**
 * A push button whose action posts ButtonClicked. The text colour is the button's content
 * tint, which AppKit applies to the title.
 */
class AppkitButton extends TKButton
{
    use AppkitPrimitive;

    /**
     * Controls hold their targets weakly: the primitive keeps it.
     * @var ObjCTarget
     */
    protected ObjCTarget $target;

    public function __construct(string $name, ToolkitWindow $window, ?TKPrimitiveGroup $parent, Placement $placement, string $label)
    {
        parent::__construct($name, $window, $parent, $placement, $label);
        // The target holds its callable where PHP's collector cannot see it: a weak reference keeps it from holding the primitive.
        $self = WeakReference::create($this);
        $this->target = new ObjCTarget(static fn () => $self->get()?->clicked());
        $this->adoptNative(NSButton::buttonWithTitleTargetAction($label, $this->target, ObjCTarget::ACTION));
    }

    public function native(): NSButton
    {
        return $this->native;
    }

    protected function applyLabel(string $label): void
    {
        $this->native->setTitle($label);
    }

    protected function applyFont(FontSpec $font): void
    {
        $this->native->setFont(self::nsFont($font));
    }

    protected function applyTextColor(?Color $color): void
    {
        $this->native->setContentTintColor(is_null($color) ? null : self::nsColor($color));
    }

    /**
     * The default holder gets the Return key equivalent, which AppKit draws as the default button.
     * AppkitButtonKeys clicks it first; the equivalent acts only on a Return the tap lets pass.
     */
    protected function applyRole(?ButtonRole $role): void
    {
        $this->native->setKeyEquivalent($role === ButtonRole::DEFAULT ? "\r" : '');
    }

    protected function releaseNative(): void
    {
        $this->native->setTarget(null);
    }

    protected function clicked(): void
    {
        $this->session()->post(new ButtonClicked($this->window->name(), $this->path(), $this->uuid));
    }
}
