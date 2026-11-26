<?php

namespace Jovian\Toolkits\Appkit;

use RuntimeException;
use Voyager\Contracts\Core\FrameworkCore;
use Surface\Toolkits\Drivers\ToolkitAdapterDriver;
use Jovian\Toolkits\Appkit\Menus\AppkitMenuBar;
use Surface\Contracts\Toolkits\UI\TKRow;
use Surface\Contracts\Toolkits\UI\TKFixed;
use Surface\Contracts\Toolkits\UI\TKLabel;
use Surface\Contracts\Toolkits\UI\TKButton;
use Surface\Contracts\Toolkits\UI\TKColumn;
use Surface\Contracts\Toolkits\UI\TKToggle;
use Surface\Contracts\Toolkits\UI\TKCheckbox;
use Surface\Contracts\Toolkits\UI\TKDropdown;
use Surface\Contracts\Toolkits\UI\TKTextInput;
use Surface\Contracts\Toolkits\UI\TKLink;
use Surface\Contracts\Toolkits\UI\TKComboBox;
use Surface\Contracts\Toolkits\UI\TKDatepicker;
use Jovian\Toolkits\Appkit\UI\AppkitTextInput;
use Jovian\Toolkits\Appkit\UI\AppkitLink;
use Jovian\Toolkits\Appkit\UI\AppkitComboBox;
use Jovian\Toolkits\Appkit\UI\AppkitDatepicker;
use Jovian\Toolkits\Appkit\UI\AppkitRow;
use Jovian\Toolkits\Appkit\UI\AppkitFixed;
use Jovian\Toolkits\Appkit\UI\AppkitLabel;
use Jovian\Toolkits\Appkit\UI\AppkitButton;
use Jovian\Toolkits\Appkit\UI\AppkitColumn;
use Jovian\Toolkits\Appkit\UI\AppkitToggle;
use Jovian\Toolkits\Appkit\UI\AppkitCheckbox;
use Jovian\Toolkits\Appkit\UI\AppkitDropdown;
use Surface\Contracts\Toolkits\UI\TKTable;
use Jovian\Toolkits\Appkit\UI\AppkitTable;
use Surface\Contracts\Toolkits\UI\TKListView;
use Jovian\Toolkits\Appkit\UI\AppkitListView;
use Surface\Contracts\Toolkits\UI\TKCollectionView;
use Jovian\Toolkits\Appkit\UI\AppkitCollectionView;
use Surface\Contracts\Toolkits\UI\TKTree;
use Jovian\Toolkits\Appkit\UI\AppkitTree;
use Surface\Contracts\Toolkits\UI\TKTextArea;
use Jovian\Toolkits\Appkit\UI\AppkitTextArea;
use Surface\Contracts\Toolkits\UI\TKScrollView;
use Jovian\Toolkits\Appkit\UI\AppkitScrollView;
use Surface\Contracts\Toolkits\UI\TKTabView;
use Jovian\Toolkits\Appkit\UI\AppkitTabView;
use Surface\Contracts\Toolkits\UI\TKSplitView;
use Jovian\Toolkits\Appkit\UI\AppkitSplitView;
use Surface\Contracts\Toolkits\UI\TKGrid;
use Jovian\Toolkits\Appkit\UI\AppkitGrid;
use Surface\Contracts\Toolkits\UI\TKSeparator;
use Jovian\Toolkits\Appkit\UI\AppkitSeparator;
use Surface\Contracts\Toolkits\UI\TKProgressBar;
use Jovian\Toolkits\Appkit\UI\AppkitProgressBar;
use Surface\Contracts\Toolkits\UI\TKSpinner;
use Jovian\Toolkits\Appkit\UI\AppkitSpinner;
use Surface\Contracts\Toolkits\UI\TKSlider;
use Jovian\Toolkits\Appkit\UI\AppkitSlider;
use Surface\Contracts\Toolkits\UI\TKToggleButton;
use Jovian\Toolkits\Appkit\UI\AppkitToggleButton;
use Surface\Contracts\Toolkits\UI\TKSegmentedControl;
use Jovian\Toolkits\Appkit\UI\AppkitSegmentedControl;
use Surface\Contracts\Toolkits\UI\TKImage;
use Jovian\Toolkits\Appkit\UI\AppkitImage;
use Surface\Contracts\Toolkits\UI\TKOverlay;
use Jovian\Toolkits\Appkit\UI\AppkitOverlay;
use Surface\Contracts\Toolkits\UI\TKWebView;
use Jovian\Toolkits\Appkit\UI\AppkitWebView;

class AppkitAdapterDriver extends ToolkitAdapterDriver
{
    protected string $window = AppkitWindow::class;

    protected ?string $menu_bar = AppkitMenuBar::class;

    protected array $primitives = [
        TKColumn::class => AppkitColumn::class,
        TKRow::class => AppkitRow::class,
        TKFixed::class => AppkitFixed::class,
        TKLabel::class => AppkitLabel::class,
        TKButton::class => AppkitButton::class,
        TKCheckbox::class => AppkitCheckbox::class,
        TKToggle::class => AppkitToggle::class,
        TKDropdown::class => AppkitDropdown::class,
        TKTextInput::class => AppkitTextInput::class,
        TKLink::class => AppkitLink::class,
        TKComboBox::class => AppkitComboBox::class,
        TKDatepicker::class => AppkitDatepicker::class,
        TKSeparator::class => AppkitSeparator::class,
        TKProgressBar::class => AppkitProgressBar::class,
        TKSpinner::class => AppkitSpinner::class,
        TKSlider::class => AppkitSlider::class,
        TKToggleButton::class => AppkitToggleButton::class,
        TKSegmentedControl::class => AppkitSegmentedControl::class,
        TKImage::class => AppkitImage::class,
        TKScrollView::class => AppkitScrollView::class,
        TKTabView::class => AppkitTabView::class,
        TKSplitView::class => AppkitSplitView::class,
        TKGrid::class => AppkitGrid::class,
        TKTable::class => AppkitTable::class,
        TKListView::class => AppkitListView::class,
        TKCollectionView::class => AppkitCollectionView::class,
        TKTree::class => AppkitTree::class,
        TKTextArea::class => AppkitTextArea::class,
        TKOverlay::class => AppkitOverlay::class,
        TKWebView::class => AppkitWebView::class,
    ];

    public function __construct(
        FrameworkCore $app
    ) {
        if (device_os_family() !== 'mac') {
            throw new RuntimeException('Appkit driver requires macOS');
        }

        if (!extension_loaded('appkit')) {
            throw new RuntimeException("ext-appkit missing. Install it with 'pie install php-io-extensions/appkit'");
        }

        parent::__construct($app);
    }

    protected function instanceSession(): void
    {
        $this->session ??= new AppkitSession($this->app);
    }
}