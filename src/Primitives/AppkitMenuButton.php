<?php

namespace Jovian\Toolkits\Appkit\Primitives;

use Jovian\Toolkits\Appkit\Primitives\Concerns\AppkitButtonImage;
use Jovian\Toolkits\Appkit\Primitives\Concerns\AppkitPrimitive;
use Windows\AppkitContextMenu;
use NSPopUpButton;
use NSRect;
use Surface\Contracts\Framebuffers\Framebuffer;
use Surface\Contracts\Toolkits\Menus\ContextMenu as ContextMenuContract;
use Surface\Contracts\Toolkits\Primitives\Placement;
use Surface\Contracts\Toolkits\ToolkitWindow;
use Surface\Toolkits\Menus\ContextMenu;
use Surface\Toolkits\Primitives\TKMenuButton;
use Surface\Toolkits\Primitives\TKPrimitiveGroup;

/**
 * An NSPopUpButton pull-down: item 0 carries the label and the image (the button shows it, the
 * menu leaves it out), the menu's items follow it, filled as a context menu fills its NSMenu. A
 * chosen item's target posts MenuActivated.
 */
class AppkitMenuButton extends TKMenuButton
{
    use AppkitPrimitive;
    use AppkitButtonImage;

    /** Holds the items' targets: NSMenuItem holds its target weakly. */
    protected ?AppkitContextMenu $items = null;

    public function __construct(string $name, ToolkitWindow $window, ?TKPrimitiveGroup $parent, Placement $placement, string $label, ContextMenuContract $menu)
    {
        parent::__construct($name, $window, $parent, $placement, $label, $menu);
        $this->adoptNative(NSPopUpButton::initWithFramePullsDown(new NSRect(0.0, 0.0, 0.0, 0.0), true));
        $this->applyMenu($menu);
    }

    public function native(): NSPopUpButton
    {
        return $this->native;
    }

    protected function applyLabel(string $label): void
    {
        $this->native->menu()?->itemAtIndex(0)?->setTitle($label);
    }

    protected function applyImage(?Framebuffer $image, float $scale): void
    {
        $this->native->menu()?->itemAtIndex(0)?->setImage(is_null($image) ? null : self::nsImage($image, $scale));
    }

    protected function applyMenu(ContextMenuContract $menu): void
    {
        $this->native->removeAllItems();
        $this->native->addItemWithTitle($this->label);
        if (! is_null($this->image)) {
            $this->applyImage($this->image, $this->image_scale);
        }
        $this->items = $menu instanceof ContextMenu
            ? new AppkitContextMenu($menu, $this->window->name(), $this->session(), $this->native->menu(), $this)
            : null;
    }

    protected function applyBordered(bool $on): void
    {
        $this->native->setBordered($on);
    }

    protected function releaseNative(): void
    {
        $this->native->removeAllItems();
        $this->items = null;
    }
}
