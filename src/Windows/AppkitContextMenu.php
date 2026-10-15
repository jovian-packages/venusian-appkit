<?php

namespace Jovian\Toolkits\Appkit\Windows;

use Jovian\Toolkits\Appkit\Bridge\AppkitSession;
use NSMenu;
use NSMenuItem;
use NSPoint;
use NSView;
use ObjCTarget;
use Surface\Contracts\Windows\Mail\MenuActivated;
use Surface\Contracts\Windows\WindowException;
use Surface\Windows\Menus\ContextMenu;
use Surface\Windows\Menus\MenuItem;

/**
 * A primitive's context menu, built for one right click: an NSMenu filled as the bar fills its
 * folders, popped up at a point in a view. AppKit tracks it until it closes; a chosen item's target
 * posts MenuActivated.
 */
final class AppkitContextMenu
{
    private readonly NSMenu $menu;

    /** @var array<string, NSMenuItem> item natives by id */
    private array $items = [];

    /** @var list<ObjCTarget> kept alive here: NSMenuItem holds its target weakly */
    private array $targets = [];

    public function __construct(
        ContextMenu $menu,
        private readonly string $window,
        private readonly AppkitSession $session,
    ) {
        $this->menu = $this->fill(NSMenu::initWithTitle(''), $menu->items);
    }

    public function menu(): NSMenu
    {
        return $this->menu;
    }

    /** @throws WindowException */
    public function item(string $id): NSMenuItem
    {
        return $this->items[$id] ?? throw new WindowException("No item '{$id}' in this context menu.");
    }

    /** Track the menu at $point in $view until it closes. Whether an item was chosen. */
    public function popupAt(NSView $view, NSPoint $point): bool
    {
        return $this->menu->popUpMenuPositioningItemAtLocationInView(null, $point, $view);
    }

    /** @param list<MenuItem> $items */
    private function fill(NSMenu $menu, array $items): NSMenu
    {
        $menu->setAutoenablesItems(false);

        foreach ($items as $item) {
            if ($item->separator) {
                $menu->addItem(NSMenuItem::separatorItem());
                continue;
            }

            if ($item->isFolder()) {
                $slot = NSMenuItem::initWithTitleActionKeyEquivalent($item->label, null, '');
                $slot->setSubmenu($this->fill(NSMenu::initWithTitle($item->label), $item->items));
                $menu->addItem($slot);
                continue;
            }

            $native = NSMenuItem::initWithTitleActionKeyEquivalent($item->label, ObjCTarget::ACTION, '');
            $target = new ObjCTarget(fn () => $this->session->post(new MenuActivated($this->window, $item->id)));
            $native->setTarget($target);
            $this->targets[] = $target;
            $this->items[$item->id] = $native;
            $menu->addItem($native);
        }

        return $menu;
    }
}
