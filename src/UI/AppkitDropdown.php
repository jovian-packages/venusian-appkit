<?php

namespace Jovian\Toolkits\Appkit\UI;

use NSRect;
use NSMenuItem;
use ObjCTarget;
use NSPopUpButton;
use WeakReference;
use Surface\Toolkits\UI\TKDropdown;
use Jovian\Toolkits\Appkit\UI\Concerns\AppkitNative;
use Jovian\Toolkits\Appkit\UI\Concerns\TakesInputFromAbove;

/**
 * A pop-up NSPopUpButton. Options go in as items of its menu, so repeated titles stay
 * separate items (addItemsWithTitles: would fold them). Its action fires on the user's
 * choice only, and tells topside the chosen index.
 */
class AppkitDropdown extends TKDropdown
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
            $dropdown = $self->get();
            $dropdown?->chosen($dropdown->popupNative()->indexOfSelectedItem());
        });

        $popup = NSPopUpButton::initWithFramePullsDown(new NSRect(0.0, 0.0, 0.0, 0.0), false);
        $popup->setTarget($this->target);
        $popup->setAction(ObjCTarget::ACTION);
        $this->adoptNative($popup);
        $this->applyOptions($this->options(), $this->selected());
    }

    protected function applyOptions(array $options, int $selected): void
    {
        $popup = $this->popupNative();
        $popup->removeAllItems();
        $menu = $popup->menu();
        foreach ($options as $option) {
            $menu->addItem(NSMenuItem::initWithTitleActionKeyEquivalent($option, null, ''));
        }
        if ($selected >= 0) {
            $popup->selectItemAtIndex($selected);
        }
    }

    protected function applySelected(int $index): void
    {
        $this->popupNative()->selectItemAtIndex($index);
    }

    protected function applyNativeEnabled(bool $takes_input): void
    {
        $this->popupNative()->setEnabled($takes_input);
    }

    protected function releaseNative(): void
    {
        $this->popupNative()->setTarget(null);
        $this->target = null;
        $this->releaseView();
    }

    private function popupNative(): NSPopUpButton
    {
        /** @var NSPopUpButton */
        return $this->native();
    }
}
