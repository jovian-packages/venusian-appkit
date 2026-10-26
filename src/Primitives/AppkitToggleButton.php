<?php

namespace Jovian\Toolkits\Appkit\Primitives;

use Jovian\Toolkits\Appkit\Primitives\Concerns\AppkitButtonImage;
use Jovian\Toolkits\Appkit\Primitives\Concerns\AppkitPrimitive;
use NSButton;
use NSButtonType;
use NSCellImagePosition;
use Surface\Contracts\Framebuffers\Framebuffer;
use Surface\Contracts\Windows\Primitives\ImagePosition;
use NSControlStateValue;
use ObjCTarget;
use Surface\Contracts\Windows\Mail\View\Toggled;
use Surface\Contracts\Windows\Primitives\Placement;
use Surface\Contracts\Windows\ToolkitWindow;
use Surface\Windows\Primitives\TKPrimitiveGroup;
use Surface\Windows\Primitives\TKToggleButton;
use WeakReference;

/**
 * A push-on/push-off NSButton: pressed is its ON state; its action posts Toggled.
 */
class AppkitToggleButton extends TKToggleButton
{
    use AppkitPrimitive;
    use AppkitButtonImage;

    /**
     * Controls hold targets weakly: the primitive keeps it.
     */
    protected ObjCTarget $target;

    public function __construct(string $name, ToolkitWindow $window, ?TKPrimitiveGroup $parent, Placement $placement, string $label, bool $pressed)
    {
        parent::__construct($name, $window, $parent, $placement, $label, $pressed);
        // The target holds its callable where PHP's collector cannot see it: a weak reference keeps it from holding the primitive.
        $self = WeakReference::create($this);
        $this->target = new ObjCTarget(static fn () => $self->get()?->toggled());
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
        $this->showBezel();
    }

    protected function applyImage(?Framebuffer $image, ImagePosition $position, float $scale): void
    {
        $this->native->setImage(is_null($image) ? null : self::nsImage($image, $scale));
        $this->native->setImagePosition(is_null($image) ? NSCellImagePosition::NO_IMAGE : self::cellPosition($position));
    }

    protected function applyBordered(bool $on): void
    {
        $this->showBezel();
    }

    /**
     * A borderless NSButton draws nothing for its ON state: a borderless toggle shows its bezel
     * while on, as Qt's flat tool button shows its frame and GTK's flat toggle its pressed look.
     */
    protected function showBezel(): void
    {
        $this->native->setBordered($this->isBordered() || $this->native->state() === NSControlStateValue::ON);
    }

    protected function releaseNative(): void
    {
        $this->native->setTarget(null);
    }

    protected function toggled(): void
    {
        $this->nativeToggled($this->native->state() === NSControlStateValue::ON);
        $this->showBezel();
        $this->session()->post(new Toggled($this->window->name(), $this->path(), $this->uuid, $this->pressed));
    }
}
