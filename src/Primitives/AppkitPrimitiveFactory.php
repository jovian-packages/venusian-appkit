<?php

namespace Jovian\Toolkits\Appkit\Primitives;

use DateTimeImmutable;
use Jovian\Toolkits\Appkit\Windows\AppkitWebView;
use Surface\Contracts\Toolkits\Menus\ContextMenu;
use Surface\Contracts\Toolkits\Primitives\Placement;
use Surface\Contracts\Toolkits\Primitives\PrimitiveFactory;
use Surface\Contracts\Toolkits\Primitives\TKButton;
use Surface\Contracts\Toolkits\Primitives\TKCanvas;
use Surface\Contracts\Toolkits\Primitives\TKCheckbox;
use Surface\Contracts\Toolkits\Primitives\TKCollectionView;
use Surface\Contracts\Toolkits\Primitives\TKColumn;
use Surface\Contracts\Toolkits\Primitives\TKComboBox;
use Surface\Contracts\Toolkits\Primitives\TKDatepicker;
use Surface\Contracts\Toolkits\Primitives\TKDropdown;
use Surface\Contracts\Toolkits\Primitives\TKFixed;
use Surface\Contracts\Toolkits\Primitives\TKGrid;
use Surface\Contracts\Toolkits\Primitives\TKImage;
use Surface\Contracts\Toolkits\Primitives\TKLabel;
use Surface\Contracts\Toolkits\Primitives\TKLink;
use Surface\Contracts\Toolkits\Primitives\TKListView;
use Surface\Contracts\Toolkits\Primitives\TKMenuButton;
use Surface\Contracts\Toolkits\Primitives\TKPrimitiveGroup;
use Surface\Contracts\Toolkits\Primitives\TKProgressBar;
use Surface\Contracts\Toolkits\Primitives\TKRow;
use Surface\Contracts\Toolkits\Primitives\TKScrollBar;
use Surface\Contracts\Toolkits\Primitives\TKScrollView;
use Surface\Contracts\Toolkits\Primitives\TKSegmentedControl;
use Surface\Contracts\Toolkits\Primitives\TKSeparator;
use Surface\Contracts\Toolkits\Primitives\TKSlider;
use Surface\Contracts\Toolkits\Primitives\TKSpinner;
use Surface\Contracts\Toolkits\Primitives\TKSplitView;
use Surface\Contracts\Toolkits\Primitives\TKTable;
use Surface\Contracts\Toolkits\Primitives\TKTabView;
use Surface\Contracts\Toolkits\Primitives\TKTextArea;
use Surface\Contracts\Toolkits\Primitives\TKTextInput;
use Surface\Contracts\Toolkits\Primitives\TKToggle;
use Surface\Contracts\Toolkits\Primitives\TKToggleButton;
use Surface\Contracts\Toolkits\Primitives\TKTree;
use Surface\Contracts\Toolkits\Primitives\TKVideo;
use Surface\Contracts\Toolkits\Primitives\TKWebView;
use Surface\Contracts\Toolkits\ToolkitRuntimeException;
use Jovian\Toolkits\Appkit\AppkitWindow;

/**
 * Mints the AppKit concretes for one window. Each mint takes the host's pending placement
 * (or the next slot for the window's content container) and builds the native in the
 * concrete's constructor.
 */
class AppkitPrimitiveFactory implements PrimitiveFactory
{
    public function __construct(
        protected readonly AppkitWindow $window,
    ) {}

    public function mintLabel(TKPrimitiveGroup $host, string $name, string $text): TKLabel
    {
        return new AppkitLabel($name, $this->window, $host, $this->placement($host), $text);
    }

    public function mintLink(TKPrimitiveGroup $host, string $name, string $text, string $url): TKLink
    {
        return new AppkitLink($name, $this->window, $host, $this->placement($host), $text, $url);
    }

    public function mintButton(TKPrimitiveGroup $host, string $name, string $label): TKButton
    {
        return new AppkitButton($name, $this->window, $host, $this->placement($host), $label);
    }

    public function mintImage(TKPrimitiveGroup $host, string $name, ?string $file): TKImage
    {
        return new AppkitImage($name, $this->window, $host, $this->placement($host), $file);
    }

    public function mintCanvas(TKPrimitiveGroup $host, string $name): TKCanvas
    {
        return new AppkitCanvas($name, $this->window, $host, $this->placement($host));
    }

    public function mintSeparator(TKPrimitiveGroup $host, string $name, bool $horizontal): TKSeparator
    {
        return new AppkitSeparator($name, $this->window, $host, $this->placement($host), $horizontal);
    }

    public function mintSpinner(TKPrimitiveGroup $host, string $name): TKSpinner
    {
        return new AppkitSpinner($name, $this->window, $host, $this->placement($host));
    }

    public function mintProgressBar(TKPrimitiveGroup $host, string $name, ?float $fraction): TKProgressBar
    {
        return new AppkitProgressBar($name, $this->window, $host, $this->placement($host), $fraction);
    }

    public function mintTextInput(TKPrimitiveGroup $host, string $name, string $value, ?string $placeholder, bool $secret): TKTextInput
    {
        return new AppkitTextInput($name, $this->window, $host, $this->placement($host), $value, $placeholder, $secret);
    }

    public function mintTextArea(TKPrimitiveGroup $host, string $name, string $value): TKTextArea
    {
        return new AppkitTextArea($name, $this->window, $host, $this->placement($host), $value);
    }

    public function mintCheckbox(TKPrimitiveGroup $host, string $name, string $label, bool $checked): TKCheckbox
    {
        return new AppkitCheckbox($name, $this->window, $host, $this->placement($host), $label, $checked);
    }

    public function mintToggle(TKPrimitiveGroup $host, string $name, bool $on): TKToggle
    {
        return new AppkitToggle($name, $this->window, $host, $this->placement($host), $on);
    }

    public function mintToggleButton(TKPrimitiveGroup $host, string $name, string $label, bool $pressed): TKToggleButton
    {
        return new AppkitToggleButton($name, $this->window, $host, $this->placement($host), $label, $pressed);
    }

    public function mintScrollBar(TKPrimitiveGroup $host, string $name, bool $vertical): TKScrollBar
    {
        return new AppkitScrollBar($name, $this->window, $host, $this->placement($host), $vertical);
    }

    public function mintSlider(TKPrimitiveGroup $host, string $name, float $min, float $max, float $value): TKSlider
    {
        return new AppkitSlider($name, $this->window, $host, $this->placement($host), $min, $max, $value);
    }

    public function mintDropdown(TKPrimitiveGroup $host, string $name, array $options, int $selected): TKDropdown
    {
        return new AppkitDropdown($name, $this->window, $host, $this->placement($host), $options, $selected);
    }

    public function mintComboBox(TKPrimitiveGroup $host, string $name, string $value, array $options): TKComboBox
    {
        return new AppkitComboBox($name, $this->window, $host, $this->placement($host), $value, $options);
    }

    public function mintMenuButton(TKPrimitiveGroup $host, string $name, string $label, ContextMenu $menu): TKMenuButton
    {
        return new AppkitMenuButton($name, $this->window, $host, $this->placement($host), $label, $menu);
    }

    public function mintDatepicker(TKPrimitiveGroup $host, string $name, ?DateTimeImmutable $date): TKDatepicker
    {
        return new AppkitDatepicker($name, $this->window, $host, $this->placement($host), $date);
    }

    public function mintTable(TKPrimitiveGroup $host, string $name, array $columns, array $rows): TKTable
    {
        return new AppkitTable($name, $this->window, $host, $this->placement($host), $columns, $rows);
    }

    public function mintSegmentedControl(TKPrimitiveGroup $host, string $name, array $segments, int $selected): TKSegmentedControl
    {
        return new AppkitSegmentedControl($name, $this->window, $host, $this->placement($host), $segments, $selected);
    }

    public function mintCollectionView(TKPrimitiveGroup $host, string $name, array $items): TKCollectionView
    {
        return new AppkitCollectionView($name, $this->window, $host, $this->placement($host), $items);
    }

    public function mintListView(TKPrimitiveGroup $host, string $name, array $items): TKListView
    {
        return new AppkitListView($name, $this->window, $host, $this->placement($host), $items);
    }

    public function mintTree(TKPrimitiveGroup $host, string $name, array $columns, array $nodes): TKTree
    {
        return new AppkitTree($name, $this->window, $host, $this->placement($host), $columns, $nodes);
    }

    public function mintVideo(TKPrimitiveGroup $host, string $name, ?string $file): TKVideo
    {
        return new AppkitVideo($name, $this->window, $host, $this->placement($host), $file);
    }

    /**
     * @throws ToolkitRuntimeException When the loaded ext-appkit has no WKWebView (a build from before its binding).
     */
    public function mintWebView(TKPrimitiveGroup $host, string $name): TKWebView
    {
        if (! class_exists('WKWebView')) {
            throw new ToolkitRuntimeException('TKWebView needs WKWebView, which this ext-appkit build does not have.');
        }

        return new AppkitWebView($name, $this->window, $host, $this->placement($host));
    }

    public function mintColumn(?TKPrimitiveGroup $host, string $name, int $spacing, int $padding): TKColumn
    {
        return new AppkitColumn($name, $this->window, $host, $this->placement($host), $spacing, $padding);
    }

    public function mintRow(?TKPrimitiveGroup $host, string $name, int $spacing, int $padding): TKRow
    {
        return new AppkitRow($name, $this->window, $host, $this->placement($host), $spacing, $padding);
    }

    public function mintGrid(?TKPrimitiveGroup $host, string $name, int $spacing, int $padding): TKGrid
    {
        return new AppkitGrid($name, $this->window, $host, $this->placement($host), $spacing, $padding);
    }

    public function mintFixed(?TKPrimitiveGroup $host, string $name): TKFixed
    {
        return new AppkitFixed($name, $this->window, $host, $this->placement($host));
    }

    public function mintScrollView(TKPrimitiveGroup $host, string $name): TKScrollView
    {
        return new AppkitScrollView($name, $this->window, $host, $this->placement($host));
    }

    public function mintSplit(TKPrimitiveGroup $host, string $name, bool $horizontal): TKSplitView
    {
        return new AppkitSplitView($name, $this->window, $host, $this->placement($host), $horizontal);
    }

    public function mintTabs(TKPrimitiveGroup $host, string $name): TKTabView
    {
        return new AppkitTabView($name, $this->window, $host, $this->placement($host));
    }

    /**
     * @param TKPrimitiveGroup|null $host
     * @return Placement
     */
    protected function placement(?TKPrimitiveGroup $host): Placement
    {
        return $host?->takePlacement() ?? Placement::next();
    }
}
