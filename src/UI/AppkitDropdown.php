<?php

namespace Jovian\Toolkits\Appkit\UI;

use NSRect;
use NSMenuItem;
use NSCellImagePosition;
use ObjCTarget;
use NSPopUpButton;
use WeakReference;
use Surface\Toolkits\UI\TKDropdown;
use Jovian\Toolkits\Appkit\UI\Support\AppkitIcon;
use Jovian\Toolkits\Appkit\UI\Concerns\AppkitNative;
use Jovian\Toolkits\Appkit\UI\Concerns\TakesInputFromAbove;

/**
 * A pop-up NSPopUpButton. Options go in as items of its menu, so repeated titles stay
 * separate items (addItemsWithTitles: would fold them). Its action fires on the user's
 * choice only, and tells topside the chosen index.
 *
 * An option's icon is its menu item's image (AppkitIcon: the file, else the SF Symbol). Icon-only
 * is the button borderless with IMAGE_ONLY: its cell takes the selected item's image for the
 * face, and AppKit's own arrow stays beside it, as a borderless pop-up shows.
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

    /** Where AppKit puts the face's image when the pop-up is built: what icon-only gives back. */
    private ?NSCellImagePosition $image_position = null;

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
        $this->image_position = $popup->imagePosition();
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

    protected function applyIcons(array $icons): void
    {
        $popup = $this->popupNative();
        $menu = $popup->menu();
        foreach (array_keys($this->options()) as $index) {
            $icon = $this->iconOf($index);
            $menu->itemAtIndex($index)?->setImage(is_null($icon) ? null : AppkitIcon::image($icon['file'], $icon['symbol'], $this->options()[$index], $popup->font()));
        }
        // The face is drawn from the selected item: selecting it again redraws it with its image.
        if ($this->selected() >= 0) {
            $popup->selectItemAtIndex($this->selected());
        }
    }

    protected function applyIconOnly(bool $on): void
    {
        $popup = $this->popupNative();
        $popup->setBordered(! $on);
        $popup->setImagePosition($on ? NSCellImagePosition::IMAGE_ONLY : $this->image_position);
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
