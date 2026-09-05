<?php

namespace Jovian\Venusian\AppKit\Views;

use Jovian\Bindings\AppKit\NS\NSControl;
use Jovian\Bindings\AppKit\NS\NSPopUpButton;
use Jovian\Bindings\AppKit\Runtime\Bridge;
use Jovian\Bindings\AppKit\Runtime\ObjCObject;
use Surface\Contracts\NativeWindows\Views\Color;
use Surface\NativeWindows\Views\Dropdown;
use Surface\NativeWindows\Windowable;

/**
 * A Surface dropdown over an NSPopUpButton. The pick lands in
 * fireSelected() with the index AppKit holds.
 */
class AppKitDropdown extends Dropdown
{
    use TranslatesAppKitFrames;

    public function __construct(
        string $name,
        Windowable $window,
        array $options,
        int $selected,
        public readonly NSPopUpButton $popup,
    ) {
        parent::__construct($name, $window, $options, $selected);

        Bridge::setAction(
            $popup->handle,
            fn (ObjCObject $sender) => $this->fireSelected($this->popup->indexOfSelectedItem()),
        );
    }

    protected function control(): NSControl
    {
        return $this->popup;
    }

    protected function applyOptions(array $options, int $selected): void
    {
        $this->popup->removeAllItems();
        if ($options !== []) {
            $this->popup->addItemsWithTitles($options);
            $this->popup->selectItemAtIndex($selected);
        }
    }

    protected function applySelected(int $selected): void
    {
        if ($selected >= 0) {
            $this->popup->selectItemAtIndex($selected);
        }
    }

    protected function applyEnabled(bool $enabled): void
    {
        $this->popup->setEnabled($enabled);
    }

    /**
     * The bezel is AppKit's — no honest fill path, the colour is ignored.
     */
    protected function applyBackground(Color $color): void {}
}
