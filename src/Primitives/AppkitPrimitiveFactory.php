<?php

namespace Jovian\Toolkits\Appkit\Primitives;

use DateTimeImmutable;
use Jovian\Toolkits\Appkit\Windows\AppkitWindow;
use Surface\Contracts\Windows\Primitives\Placement;
use Surface\Contracts\Windows\Primitives\PrimitiveFactory;
use Surface\Contracts\Windows\Primitives\TKButton;
use Surface\Contracts\Windows\Primitives\TKCanvas;
use Surface\Contracts\Windows\Primitives\TKCheckbox;
use Surface\Contracts\Windows\Primitives\TKColumn;
use Surface\Contracts\Windows\Primitives\TKDatepicker;
use Surface\Contracts\Windows\Primitives\TKDropdown;
use Surface\Contracts\Windows\Primitives\TKFixed;
use Surface\Contracts\Windows\Primitives\TKGrid;
use Surface\Contracts\Windows\Primitives\TKImage;
use Surface\Contracts\Windows\Primitives\TKLabel;
use Surface\Contracts\Windows\Primitives\TKPrimitiveGroup;
use Surface\Contracts\Windows\Primitives\TKProgressBar;
use Surface\Contracts\Windows\Primitives\TKRow;
use Surface\Contracts\Windows\Primitives\TKScrollView;
use Surface\Contracts\Windows\Primitives\TKSeparator;
use Surface\Contracts\Windows\Primitives\TKSlider;
use Surface\Contracts\Windows\Primitives\TKSpinner;
use Surface\Contracts\Windows\Primitives\TKTable;
use Surface\Contracts\Windows\Primitives\TKTextArea;
use Surface\Contracts\Windows\Primitives\TKTextInput;
use Surface\Contracts\Windows\Primitives\TKToggle;
use Surface\Contracts\Windows\Primitives\TKToggleButton;
use Surface\Contracts\Windows\Primitives\TKVideo;

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

    public function mintSlider(TKPrimitiveGroup $host, string $name, float $min, float $max, float $value): TKSlider
    {
        return new AppkitSlider($name, $this->window, $host, $this->placement($host), $min, $max, $value);
    }

    public function mintDropdown(TKPrimitiveGroup $host, string $name, array $options, int $selected): TKDropdown
    {
        return new AppkitDropdown($name, $this->window, $host, $this->placement($host), $options, $selected);
    }

    public function mintDatepicker(TKPrimitiveGroup $host, string $name, ?DateTimeImmutable $date): TKDatepicker
    {
        return new AppkitDatepicker($name, $this->window, $host, $this->placement($host), $date);
    }

    public function mintTable(TKPrimitiveGroup $host, string $name, array $columns, array $rows): TKTable
    {
        return new AppkitTable($name, $this->window, $host, $this->placement($host), $columns, $rows);
    }

    public function mintVideo(TKPrimitiveGroup $host, string $name, ?string $file): TKVideo
    {
        return new AppkitVideo($name, $this->window, $host, $this->placement($host), $file);
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

    /**
     * @param TKPrimitiveGroup|null $host
     * @return Placement
     */
    protected function placement(?TKPrimitiveGroup $host): Placement
    {
        return $host?->takePlacement() ?? Placement::next();
    }
}
