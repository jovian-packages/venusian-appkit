<?php

namespace Jovian\Toolkits\Appkit\Primitives;

use Jovian\Toolkits\Appkit\Primitives\Concerns\AppkitPrimitive;
use NSMenuItem;
use NSPopUpButton;
use NSRect;
use ObjCTarget;
use Surface\Contracts\Windows\Mail\View\SelectionChanged;
use Surface\Contracts\Windows\Primitives\Placement;
use Surface\Contracts\Windows\ToolkitWindow;
use Surface\Windows\Primitives\TKDropdown;
use Surface\Windows\Primitives\TKPrimitiveGroup;

/**
 * A pop-up NSPopUpButton. Options go in as items of its menu, so repeated titles stay
 * separate items (addItemsWithTitles: would fold them). Its action posts SelectionChanged.
 */
class AppkitDropdown extends TKDropdown
{
    use AppkitPrimitive;

    /**
     * Controls hold targets weakly: the primitive keeps it.
     */
    protected ObjCTarget $target;

    public function __construct(string $name, ToolkitWindow $window, ?TKPrimitiveGroup $parent, Placement $placement, array $options, int $selected)
    {
        parent::__construct($name, $window, $parent, $placement, $options, $selected);
        $this->target = new ObjCTarget(fn () => $this->chosen());
        $popup = $this->adoptNative(NSPopUpButton::initWithFramePullsDown(new NSRect(), false));
        $popup->setTarget($this->target);
        $popup->setAction(ObjCTarget::ACTION);
        $this->applyOptions($this->options);
        $this->applySelected($this->selected);
    }

    public function native(): NSPopUpButton
    {
        return $this->native;
    }

    protected function applyOptions(array $options): void
    {
        $this->native->removeAllItems();
        $menu = $this->native->menu();
        foreach ($options as $option) {
            $menu->addItem(NSMenuItem::initWithTitleActionKeyEquivalent($option, null, ''));
        }
    }

    protected function applySelected(int $index): void
    {
        $this->native->selectItemAtIndex($index);
    }

    protected function releaseNative(): void
    {
        $this->native->setTarget(null);
    }

    protected function chosen(): void
    {
        $this->nativeSelected($this->native->indexOfSelectedItem());
        $this->session()->post(new SelectionChanged($this->window->name(), $this->path(), $this->uuid, $this->selected, $this->selectedOption()));
    }
}
