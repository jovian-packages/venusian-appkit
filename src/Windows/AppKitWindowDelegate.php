<?php

namespace Jovian\Venusian\AppKit\Windows;

use Jovian\Bindings\AppKit\NS\NSApplication;
use Jovian\Bindings\AppKit\NS\NSMenu;
use Jovian\Bindings\AppKit\NS\NSMenuItem;
use Jovian\Bindings\AppKit\NS\NSButton;
use Jovian\Bindings\AppKit\NS\NSTextField;
use Jovian\Bindings\AppKit\NS\NSView;
use Jovian\Bindings\AppKit\NS\NSWindow;
use Jovian\Bindings\AppKit\Runtime\Bridge;
use Jovian\Bindings\AppKit\Runtime\Delegate;
use Jovian\Bindings\AppKit\Runtime\ObjCObject;
use Jovian\Venusian\AppKit\Exceptions\AppKitWindowException;
use Jovian\Bindings\AppKit\AV\AVPlayerView;
use Jovian\Bindings\AppKit\Enums\AVPlayerViewControlsStyle;
use Jovian\Bindings\AppKit\Enums\NSBoxType;
use Jovian\Bindings\AppKit\Enums\NSButtonType;
use Jovian\Bindings\AppKit\Enums\NSImageScaling;
use Jovian\Bindings\AppKit\Enums\NSProgressIndicatorStyle;
use Jovian\Bindings\AppKit\NS\NSBox;
use Jovian\Bindings\AppKit\NS\NSImage;
use Jovian\Bindings\AppKit\NS\NSImageView;
use Jovian\Bindings\AppKit\NS\NSPopUpButton;
use Jovian\Bindings\AppKit\NS\NSProgressIndicator;
use Jovian\Bindings\AppKit\NS\NSScrollView;
use Jovian\Bindings\AppKit\NS\NSSecureTextField;
use Jovian\Bindings\AppKit\NS\NSSlider;
use Jovian\Bindings\AppKit\NS\NSSwitch;
use Jovian\Bindings\AppKit\NS\NSTextView;
use Jovian\Bindings\AppKit\Values\NSRect;
use Jovian\Venusian\AppKit\Views\AppKitButton;
use Jovian\Venusian\AppKit\Views\AppKitCheckbox;
use Jovian\Venusian\AppKit\Views\AppKitDropdown;
use Jovian\Venusian\AppKit\Views\AppKitGroup;
use Jovian\Venusian\AppKit\Views\AppKitImage;
use Jovian\Venusian\AppKit\Views\AppKitLabel;
use Jovian\Venusian\AppKit\Views\AppKitProgressBar;
use Jovian\Venusian\AppKit\Views\AppKitScrollView;
use Jovian\Venusian\AppKit\Views\AppKitSeparator;
use Jovian\Venusian\AppKit\Views\AppKitSlider;
use Jovian\Venusian\AppKit\Views\AppKitSpinner;
use Jovian\Venusian\AppKit\Views\AppKitTextArea;
use Jovian\Venusian\AppKit\Views\AppKitTextInput;
use Jovian\Venusian\AppKit\Views\AppKitToggle;
use Jovian\Venusian\AppKit\Views\AppKitToggleButton;
use Jovian\Venusian\AppKit\Views\AppKitVideo;
use Jovian\Venusian\AppKit\Views\HostsAppKitChildren;
use Surface\Contracts\Core\AboutInfo;
use Surface\Contracts\NativeWindows\MacOSWindow;
use Surface\Contracts\NativeWindows\Views\OSGroup;
use Surface\NativeWindows\Enums\MenuRole;
use Surface\NativeWindows\Menus\MenuItemSpec;
use Surface\NativeWindows\Views\Button;
use Surface\NativeWindows\Views\Checkbox;
use Surface\NativeWindows\Views\Dropdown;
use Surface\NativeWindows\Views\Group;
use Surface\NativeWindows\Views\Image;
use Surface\NativeWindows\Views\Label;
use Surface\NativeWindows\Views\ProgressBar;
use Surface\NativeWindows\Views\ScrollView;
use Surface\NativeWindows\Views\Separator;
use Surface\NativeWindows\Views\Slider;
use Surface\NativeWindows\Views\Spinner;
use Surface\NativeWindows\Views\TextArea;
use Surface\NativeWindows\Views\TextInput;
use Surface\NativeWindows\Views\Toggle;
use Surface\NativeWindows\Views\ToggleButton;
use Surface\NativeWindows\Views\Video;
use Surface\NativeWindows\Windowable;

class AppKitWindowDelegate extends Windowable implements MacOSWindow
{
    /**
     * The bar built from this window's elected profile, held for the later
     * focus-swap slice. macOS has one bar per process, so electing here means
     * building the tree and swapping it in as the main menu.
     * @var NSMenu|null
     */
    protected ?NSMenu $menu_bar = null;

    /**
     * The NSWindowDelegate hearing windowShouldClose:. Held for the life of
     * the window — PHP refcount owns the native delegate.
     * @var Delegate
     */
    protected Delegate $window_delegate;

    public function __construct(
        string $name,
        public readonly NSWindow $window,
        public readonly NSView $content,
    ) {
        parent::__construct($name);
        // AppKit would free the window on close while the PHP registry still owns
        // the handle. Not optional.
        $window->setReleasedWhenClosed(false);

        // Closing here only hides (see above), so the event is the sketch's
        // one signal that the user dismissed the window. Answer true: allow.
        $this->window_delegate = new Delegate('NSWindowDelegate');
        $this->window_delegate->on('windowShouldClose:', function (mixed ...$args): bool {
            $this->emitWindowClosed();

            return true;
        });
        $window->setDelegate($this->window_delegate->handle());
    }

    public function setTitle(string $title): static
    {
        $this->window->setTitle($title);
        return $this;
    }

    public function getTitle(): ?string
    {
        return $this->window->title();
    }

    public function center(): static
    {
        $this->window->center();
        return $this;
    }

    public function destroy(): void
    {
        $this->window->close();
    }

    public function present(): void
    {
        if(!$this->isPresenting()) {
            $this->window->makeKeyAndOrderFront(0);
        }
    }

    public function isPresenting(): bool
    {
        return $this->window->isVisible();
    }

    /**
     * The standard About panel, carrying the registered identity through the
     * options dict — the ext marshals the PHP array to NSDictionary. Without
     * identity the bare panel shows what the process is ("php" unbundled).
     * Credits is skipped: that key wants an NSAttributedString.
     */
    protected function presentAbout(?AboutInfo $about): void
    {
        $app = NSApplication::sharedApplication();

        if (is_null($about)) {
            $app->orderFrontStandardAboutPanel(0);

            return;
        }

        $options = ['ApplicationName' => $about->name];
        if (! is_null($about->version)) {
            $options['ApplicationVersion'] = $about->version;
            $options['Version'] = $about->version;
        }
        if (! is_null($about->copyright)) {
            $options['Copyright'] = $about->copyright;
        }

        $app->orderFrontStandardAboutPanelWithOptions($options);
    }

    /**
     * The content view's frame. Authoritative at once on AppKit — a content
     * rect IS the size at construction.
     */
    public function contentSize(): array
    {
        $frame = $this->content->frame();

        return [(int) $frame->width, (int) $frame->height];
    }

    /**
     * The native surface a mint attaches to: a hosting container's child
     * surface, or the window content.
     */
    protected function mintSurface(?OSGroup $in): NSView
    {
        return $in instanceof HostsAppKitChildren ? $in->childSurface() : $this->content;
    }

    /**
     * labelWithString: gives a non-editable, non-bezeled, background-less
     * field already sized to its text. Attached to the content here; placed
     * by Windowable::label().
     *
     * @throws AppKitWindowException When AppKit will not mint the field.
     */
    protected function mintLabel(string $name, string $text, ?OSGroup $in): Label
    {
        $field = NSTextField::labelWithString($text);
        if (! $field instanceof NSTextField) {
            throw AppKitWindowException::viewMintFailed($name);
        }

        $this->mintSurface($in)->addSubview($field->handle);

        return new AppKitLabel($name, $this, $text, $field);
    }

    /**
     * A momentary push button, minted with no target — the AppKitButton
     * wires the bridge's shared action target to fireClick() itself.
     * @throws AppKitWindowException When AppKit will not mint the button.
     */
    protected function mintButton(string $name, string $label, ?OSGroup $in): Button
    {
        $button = NSButton::buttonWithTitleTargetAction($label, 0, '');
        if (! $button instanceof NSButton) {
            throw AppKitWindowException::viewMintFailed($name);
        }

        $this->mintSurface($in)->addSubview($button->handle);

        return new AppKitButton($name, $this, $label, $button);
    }

    /**
     * An indeterminate spinner in the spinning style, hidden while stopped
     * so a stopped spinner costs nothing visually.
     * @throws AppKitWindowException When AppKit will not mint the indicator.
     */
    protected function mintSpinner(string $name, ?OSGroup $in): Spinner
    {
        $indicator = NSProgressIndicator::initWithFrame(new NSRect(0.0, 0.0, 32.0, 32.0));
        if (! $indicator instanceof NSProgressIndicator) {
            throw AppKitWindowException::viewMintFailed($name);
        }

        $indicator->setStyle(NSProgressIndicatorStyle::SPINNING);
        $indicator->setIndeterminate(true);
        $indicator->setDisplayedWhenStopped(false);

        $this->mintSurface($in)->addSubview($indicator->handle);

        return new AppKitSpinner($name, $this, $indicator);
    }

    /**
     * An NSImageView scaling proportionally up or down. A null path mints
     * an empty NSImage to swap out through setPath() later.
     * @throws AppKitWindowException When AppKit will not read the file or mint the view.
     */
    protected function mintImage(string $name, ?string $path, ?OSGroup $in): Image
    {
        // Both boxes live in locals until AppKit has retained natively —
        // never chain ->handle off a temp.
        $ns_image = is_null($path) ? NSImage::init() : NSImage::initWithContentsOfFile($path);
        if (! $ns_image instanceof NSImage) {
            throw AppKitWindowException::viewMintFailed($name);
        }

        $view = NSImageView::imageViewWithImage($ns_image->handle);
        if (! $view instanceof NSImageView) {
            throw AppKitWindowException::viewMintFailed($name);
        }

        $view->setImageScaling(NSImageScaling::NS_IMAGE_SCALE_PROPORTIONALLY_UP_OR_DOWN);

        $this->mintSurface($in)->addSubview($view->handle);

        return new AppKitImage($name, $this, $path, $view);
    }

    /**
     * An AVPlayerView with inline native controls; the AppKitVideo mints
     * an AVPlayer per path itself. Placed by Windowable::video().
     * @throws AppKitWindowException When AVKit will not mint the view.
     */
    protected function mintVideo(string $name, ?string $path, ?OSGroup $in): Video
    {
        $view = AVPlayerView::initWithFrame(new NSRect(0.0, 0.0, 320.0, 240.0));
        if (! $view instanceof AVPlayerView) {
            throw AppKitWindowException::viewMintFailed($name);
        }

        $view->setControlsStyle(AVPlayerViewControlsStyle::INLINE);

        $this->mintSurface($in)->addSubview($view->handle);

        $video = new AppKitVideo($name, $this, null, $view);
        if (! is_null($path)) {
            $video->setPath($path);
        }

        return $video;
    }

    /**
     * A text field, or a secure one for a secret input — same API, AppKit
     * masks the glyphs. The AppKitTextInput wires its own delegate and
     * action.
     * @throws AppKitWindowException When AppKit will not mint the field.
     */
    protected function mintTextInput(string $name, string $value, ?string $placeholder, bool $secret, ?OSGroup $in): TextInput
    {
        $field = $secret
            ? NSSecureTextField::initWithFrame(new NSRect(0.0, 0.0, 200.0, 28.0))
            : NSTextField::textFieldWithString($value);
        if (! $field instanceof NSTextField) {
            throw AppKitWindowException::viewMintFailed($name);
        }

        if ($secret) {
            $field->setStringValue($value);
        }
        if (! is_null($placeholder)) {
            $field->setPlaceholderString($placeholder);
        }

        $this->mintSurface($in)->addSubview($field->handle);

        return new AppKitTextInput($name, $this, $value, $placeholder, $secret, $field);
    }

    /**
     * AppKit's own pre-wired editor pair — scrollableTextView() hands back
     * an NSScrollView whose document is a fully configured NSTextView.
     * @throws AppKitWindowException When AppKit will not mint the pair.
     */
    protected function mintTextArea(string $name, string $value, ?OSGroup $in): TextArea
    {
        $scroll = NSTextView::scrollableTextView();
        if (! $scroll instanceof NSScrollView) {
            throw AppKitWindowException::viewMintFailed($name);
        }

        $text = $scroll->documentView();
        if (! $text instanceof NSTextView) {
            throw AppKitWindowException::viewMintFailed($name);
        }

        $text->setString($value);

        $this->mintSurface($in)->addSubview($scroll->handle);

        return new AppKitTextArea($name, $this, $value, $scroll, $text);
    }

    /**
     * A continuous slider minted with no target — the AppKitSlider wires
     * the bridge's shared action target itself.
     * @throws AppKitWindowException When AppKit will not mint the slider.
     */
    protected function mintSlider(string $name, float $min, float $max, float $value, ?OSGroup $in): Slider
    {
        $slider = NSSlider::sliderWithValueMinValueMaxValueTargetAction($value, $min, $max, 0, '');
        if (! $slider instanceof NSSlider) {
            throw AppKitWindowException::viewMintFailed($name);
        }

        $slider->setContinuous(true);

        $this->mintSurface($in)->addSubview($slider->handle);

        return new AppKitSlider($name, $this, $min, $max, $value, $slider);
    }

    /**
     * An NSSwitch holding the initial state; the AppKitToggle wires the
     * action itself.
     * @throws AppKitWindowException When AppKit will not mint the switch.
     */
    protected function mintToggle(string $name, bool $on, ?OSGroup $in): Toggle
    {
        $switch = NSSwitch::initWithFrame(new NSRect(0.0, 0.0, 38.0, 22.0));
        if (! $switch instanceof NSSwitch) {
            throw AppKitWindowException::viewMintFailed($name);
        }

        $switch->setState($on ? 1 : 0);

        $this->mintSurface($in)->addSubview($switch->handle);

        return new AppKitToggle($name, $this, $on, $switch);
    }

    /**
     * A push-on/push-off NSButton; the AppKitToggleButton wires the action
     * itself.
     * @throws AppKitWindowException When AppKit will not mint the button.
     */
    protected function mintToggleButton(string $name, string $label, bool $pressed, ?OSGroup $in): ToggleButton
    {
        $button = NSButton::buttonWithTitleTargetAction($label, 0, '');
        if (! $button instanceof NSButton) {
            throw AppKitWindowException::viewMintFailed($name);
        }

        $button->setButtonType(NSButtonType::PUSH_ON_PUSH_OFF);
        $button->setState($pressed ? 1 : 0);

        $this->mintSurface($in)->addSubview($button->handle);

        return new AppKitToggleButton($name, $this, $label, $pressed, $button);
    }

    /**
     * AppKit's checkbox factory; the AppKitCheckbox wires the action itself.
     * @throws AppKitWindowException When AppKit will not mint the checkbox.
     */
    protected function mintCheckbox(string $name, string $label, bool $checked, ?OSGroup $in): Checkbox
    {
        $button = NSButton::checkboxWithTitleTargetAction($label, 0, '');
        if (! $button instanceof NSButton) {
            throw AppKitWindowException::viewMintFailed($name);
        }

        $button->setState($checked ? 1 : 0);

        $this->mintSurface($in)->addSubview($button->handle);

        return new AppKitCheckbox($name, $this, $label, $checked, $button);
    }

    /**
     * A determinate bar ranged 0..1, matching Surface's promise.
     * @throws AppKitWindowException When AppKit will not mint the indicator.
     */
    protected function mintProgressBar(string $name, float $progress, ?OSGroup $in): ProgressBar
    {
        $indicator = NSProgressIndicator::initWithFrame(new NSRect(0.0, 0.0, 160.0, 8.0));
        if (! $indicator instanceof NSProgressIndicator) {
            throw AppKitWindowException::viewMintFailed($name);
        }

        $indicator->setStyle(NSProgressIndicatorStyle::BAR);
        $indicator->setIndeterminate(false);
        $indicator->setMinValue(0.0);
        $indicator->setMaxValue(1.0);
        $indicator->setDoubleValue($progress);

        $this->mintSurface($in)->addSubview($indicator->handle);

        return new AppKitProgressBar($name, $this, $progress, $indicator);
    }

    /**
     * A pop-up (not pull-down) button filled with the options; the
     * AppKitDropdown wires the action itself.
     * @throws AppKitWindowException When AppKit will not mint the button.
     */
    protected function mintDropdown(string $name, array $options, int $selected, ?OSGroup $in): Dropdown
    {
        $popup = NSPopUpButton::initWithFramePullsDown(new NSRect(0.0, 0.0, 160.0, 28.0), false);
        if (! $popup instanceof NSPopUpButton) {
            throw AppKitWindowException::viewMintFailed($name);
        }

        if ($options !== []) {
            $popup->addItemsWithTitles(array_values($options));
            $popup->selectItemAtIndex(max(0, min(count($options) - 1, $selected)));
        }

        $this->mintSurface($in)->addSubview($popup->handle);

        return new AppKitDropdown($name, $this, $options, $selected, $popup);
    }

    /**
     * An NSBox in the separator type — AppKit orients the line from the
     * frame's aspect on its own.
     * @throws AppKitWindowException When AppKit will not mint the box.
     */
    protected function mintSeparator(string $name, bool $horizontal, ?OSGroup $in): Separator
    {
        $box = NSBox::initWithFrame(new NSRect(0.0, 0.0, $horizontal ? 100.0 : 1.0, $horizontal ? 1.0 : 100.0));
        if (! $box instanceof NSBox) {
            throw AppKitWindowException::viewMintFailed($name);
        }

        $box->setBoxType(NSBoxType::NS_BOX_SEPARATOR);

        $this->mintSurface($in)->addSubview($box->handle);

        return new AppKitSeparator($name, $this, $horizontal, $box);
    }

    /**
     * A plain NSView as the container surface. Children conjured into the
     * group mint straight onto it.
     * @throws AppKitWindowException When AppKit will not mint the view.
     */
    protected function mintGroup(string $name, ?OSGroup $in): Group
    {
        $view = NSView::initWithFrame(new NSRect(0.0, 0.0, 0.0, 0.0));
        if (! $view instanceof NSView) {
            throw AppKitWindowException::viewMintFailed($name);
        }

        $this->mintSurface($in)->addSubview($view->handle);

        return new AppKitGroup($name, $this, $view);
    }

    /**
     * An NSScrollView over a plain NSView document. Children conjured into
     * the scroll view mint onto the document; AppKit owns the scrollbars.
     * @throws AppKitWindowException When AppKit will not mint the pair.
     */
    protected function mintScrollView(string $name, ?OSGroup $in): ScrollView
    {
        $scroll = NSScrollView::initWithFrame(new NSRect(0.0, 0.0, 0.0, 0.0));
        if (! $scroll instanceof NSScrollView) {
            throw AppKitWindowException::viewMintFailed($name);
        }

        $document = NSView::initWithFrame(new NSRect(0.0, 0.0, 0.0, 0.0));
        if (! $document instanceof NSView) {
            throw AppKitWindowException::viewMintFailed($name);
        }

        $scroll->setDocumentView($document->handle);
        $scroll->setHasVerticalScroller(true);
        $scroll->setHasHorizontalScroller(false);
        $scroll->setAutohidesScrollers(true);
        $scroll->setDrawsBackground(false);

        $this->mintSurface($in)->addSubview($scroll->handle);

        return new AppKitScrollView($name, $this, $scroll, $document);
    }

    /**
     * Build the elected profile into an NSMenu tree and swap it in as the
     * process's main menu.
     *
     * The bar is process-global on macOS, so with a single window this is a
     * straight swap. Multi-window focus-following comes later and reuses the
     * $menu_bar held here.
     *
     * @param list<MenuItemSpec> $spec
     * @return void
     * @throws AppKitWindowException When AppKit will not mint a menu or item.
     */
    protected function applyMenuBar(array $spec): void
    {
        $main = NSMenu::initWithTitle('MainMenu');
        if (! $main instanceof NSMenu) {
            throw AppKitWindowException::menuMintFailed('MainMenu');
        }

        foreach ($spec as $folder) {
            $slot = $this->buildFolderSlot($folder);
            $main->addItem($slot->handle);
        }

        $this->menu_bar = $main;
        /** @var NSApplication $app */
        $app = NSApplication::sharedApplication();
        $app->setMainMenu($main->handle);
    }

    /**
     * Build one top-level bar slot: an item whose submenu holds the folder.
     *
     * The first slot's rendered title is always the process name — AppKit
     * ignores whatever label the profile gave it.
     *
     * @param MenuItemSpec $folder
     * @return NSMenuItem
     * @throws AppKitWindowException
     */
    protected function buildFolderSlot(MenuItemSpec $folder): NSMenuItem
    {
        $slot = NSMenuItem::initWithTitleActionKeyEquivalent($folder->label, '', '');
        if (! $slot instanceof NSMenuItem) {
            throw AppKitWindowException::menuMintFailed($folder->label);
        }

        $menu = NSMenu::initWithTitle($folder->label);
        if (! $menu instanceof NSMenu) {
            throw AppKitWindowException::menuMintFailed($folder->label);
        }

        $this->fillMenu($menu, $folder->items);
        $slot->setSubmenu($menu->handle);

        return $slot;
    }

    /**
     * Append every spec node into a menu, recursing through nested folders.
     *
     * @param NSMenu $menu
     * @param list<MenuItemSpec> $items
     * @return void
     * @throws AppKitWindowException
     */
    protected function fillMenu(NSMenu $menu, array $items): void
    {
        foreach ($items as $item) {
            if ($item->separator) {
                $separator = NSMenuItem::separatorItem();
                if ($separator instanceof NSMenuItem) {
                    $menu->addItem($separator->handle);
                }
                continue;
            }

            if ($item->isFolder()) {
                $menu->addItem($this->buildFolderSlot($item)->handle);
                continue;
            }

            $selector = is_null($item->role) ? '' : $this->selectorForRole($item->role);
            $ns_item = NSMenuItem::initWithTitleActionKeyEquivalent($item->label, $selector, $item->hotkey ?? '');
            if (! $ns_item instanceof NSMenuItem) {
                throw AppKitWindowException::menuMintFailed($item->label);
            }

            $menu->addItem($ns_item->handle);

            if (! is_null($item->event)) {
                $this->hookEvent($ns_item, $item);
            }

            // ABOUT is PHP-backed rather than a bare selector so the panel
            // carries the registered identity instead of the process name.
            if ($item->role === MenuRole::ABOUT) {
                Bridge::setAction($ns_item->handle, fn (ObjCObject $sender) => $this->showAbout());
            }
        }
    }

    /**
     * Route an item's activation into the event queue through the bridge's
     * shared action target. Nothing user-authored runs inside the pump —
     * the sketch drains the named event after its tick.
     *
     * @param NSMenuItem $ns_item
     * @param MenuItemSpec $item
     * @return void
     */
    protected function hookEvent(NSMenuItem $ns_item, MenuItemSpec $item): void
    {
        Bridge::setAction(
            $ns_item->handle,
            fn (ObjCObject $sender) => $this->emitMenuEvent($item),
        );
    }

    /**
     * AppKit's half of the role table: engine-neutral intent to selector.
     * @param MenuRole $role
     * @return string
     */
    protected function selectorForRole(MenuRole $role): string
    {
        return match ($role) {
            MenuRole::QUIT => 'terminate:',
            MenuRole::ABOUT => '',
            MenuRole::HIDE => 'hide:',
            MenuRole::CLOSE_WINDOW => 'performClose:',
            MenuRole::MINIMIZE => 'performMiniaturize:',
            MenuRole::FULLSCREEN => 'toggleFullScreen:',
        };
    }
}
