<?php

namespace Jovian\Toolkits\Appkit\Windows;

use Jovian\Toolkits\Appkit\Bridge\AppkitSession;
use NSControlStateValue;
use NSMenu;
use NSMenuItem;
use NSPoint;
use NSView;
use ObjCTarget;
use Surface\Contracts\Windows\Mail\MenuActivated;
use Surface\Contracts\Windows\Mail\MenuRadioChosen;
use Surface\Contracts\Windows\Mail\MenuToggled;
use Surface\Contracts\Windows\WindowException;
use Surface\Windows\Menus\ContextMenu;
use Surface\Windows\Menus\MenuItem;
use Surface\Windows\Menus\MenuStates;
use WeakReference;
/**
 * A primitive's context menu, built for one right click: an NSMenu filled as the bar fills its
 * folders, popped up at a point in a view. AppKit tracks it until it closes; a chosen item's target
 * posts MenuActivated. A node that is not effectively enabled is a disabled item (autoenable is off).
 */
final class AppkitContextMenu
{
    private readonly NSMenu $menu;

    /** @var array<string, NSMenuItem> item natives by id */
    private array $items = [];

    /** @var array<string, NSMenuItem> item and folder natives by id */
    private array $nodes = [];

    /** @var list<ObjCTarget> kept alive here: NSMenuItem holds its target weakly */
    private array $targets = [];

    /** @var array<string, list<NSMenuItem>> group → its items' natives */
    private array $groups = [];

    /** @var array<string, string> group → the item on */
    private array $radios;

    /** @var WeakReference<object>|null the primitive or menu button whose menu this is: it keeps the user's choices */
    private readonly ?WeakReference $owner;

    /**
     * @param NSMenu|null $into a menu to add the items to (a pull-down's, after its title item); null makes one
     * @param object|null $owner a TKPrimitive or TKMenuButton: told the user's toggle and radio choices
     *                           (nativeContextToggled(), nativeContextRadio()) before the mail; null keeps nothing (popupMenu)
     */
    public function __construct(
        ContextMenu $menu,
        private readonly string $window,
        private readonly AppkitSession $session,
        ?NSMenu $into = null,
        ?object $owner = null,
    ) {
        $this->owner = is_null($owner) ? null : WeakReference::create($owner);
        $this->radios = $menu->radios();
        $this->menu = $this->fill($into ?? NSMenu::initWithTitle(''), $menu->items);
        $states = new MenuStates($menu);
        foreach ($this->nodes as $id => $native) {
            $native->setEnabled($states->effective($id));
        }
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

    /** An item or folder native, by id. @throws WindowException */
    public function node(string $id): NSMenuItem
    {
        return $this->nodes[$id] ?? throw new WindowException("No node '{$id}' in this context menu.");
    }

    /** Track the menu at $point in $view until it closes. Whether an item was chosen. */
    public function popupAt(NSView $view, NSPoint $point): bool
    {
        return $this->menu->popUpMenuPositioningItemAtLocationInView(null, $point, $view);
    }

    /** @param list<MenuItem> $items */
    /** A toggle item chosen: flip it, tell the owner, post MenuToggled. */
    private function toggled(string $id, NSMenuItem $native): void
    {
        $on = $native->state() !== NSControlStateValue::ON;
        $native->setState($on ? NSControlStateValue::ON : NSControlStateValue::OFF);
        $this->owner?->get()?->nativeContextToggled($id, $on);
        $this->session->post(new MenuToggled($this->window, $id, $on));
    }

    /** A radio item chosen: when it is another than the one on, it alone shows on; the owner is told, MenuRadioChosen posts. */
    private function chose(string $group, string $id, NSMenuItem $native): void
    {
        if (($this->radios[$group] ?? null) === $id) {
            return;
        }
        $this->radios[$group] = $id;
        foreach ($this->groups[$group] as $member) {
            $member->setState($member === $native ? NSControlStateValue::ON : NSControlStateValue::OFF);
        }
        $this->owner?->get()?->nativeContextRadio($group, $id);
        $this->session->post(new MenuRadioChosen($this->window, $group, $id));
    }

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
                $this->nodes[$item->id] = $slot;
                $menu->addItem($slot);
                continue;
            }

            $native = NSMenuItem::initWithTitleActionKeyEquivalent($item->label, ObjCTarget::ACTION, '');
            // The target holds its callable where PHP's collector cannot see it: static, holding this menu weakly.
            $session = $this->session;
            $window = $this->window;
            $id = $item->id;
            $self = WeakReference::create($this);
            $group = $item->radio();
            if ($item->toggle || ! is_null($group)) {
                $native->setState($item->isOn() ? NSControlStateValue::ON : NSControlStateValue::OFF);
            }
            if (! is_null($group)) {
                $this->groups[$group][] = $native;
            }
            $target = new ObjCTarget(match (true) {
                $item->toggle => static fn () => $self->get()?->toggled($id, $native),
                ! is_null($group) => static fn () => $self->get()?->chose($group, $id, $native),
                default => static fn () => $session->post(new MenuActivated($window, $id)),
            });
            $native->setTarget($target);
            $this->targets[] = $target;
            $this->items[$item->id] = $native;
            $this->nodes[$item->id] = $native;
            $menu->addItem($native);
        }

        return $menu;
    }
}
