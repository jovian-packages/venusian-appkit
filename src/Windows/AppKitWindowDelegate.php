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
use Jovian\Bindings\AppKit\Enums\NSImageScaling;
use Jovian\Bindings\AppKit\Enums\NSProgressIndicatorStyle;
use Jovian\Bindings\AppKit\NS\NSImage;
use Jovian\Bindings\AppKit\NS\NSImageView;
use Jovian\Bindings\AppKit\NS\NSProgressIndicator;
use Jovian\Bindings\AppKit\Values\NSRect;
use Jovian\Venusian\AppKit\Views\AppKitButton;
use Jovian\Venusian\AppKit\Views\AppKitImage;
use Jovian\Venusian\AppKit\Views\AppKitLabel;
use Jovian\Venusian\AppKit\Views\AppKitSpinner;
use Jovian\Venusian\AppKit\Views\AppKitVideo;
use Surface\Contracts\Core\AboutInfo;
use Surface\Contracts\NativeWindows\MacOSWindow;
use Surface\NativeWindows\Enums\MenuRole;
use Surface\NativeWindows\Menus\MenuItemSpec;
use Surface\NativeWindows\Views\Button;
use Surface\NativeWindows\Views\Image;
use Surface\NativeWindows\Views\Label;
use Surface\NativeWindows\Views\Spinner;
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
     * labelWithString: gives a non-editable, non-bezeled, background-less
     * field already sized to its text. Attached to the content here; placed
     * by Windowable::label().
     *
     * @throws AppKitWindowException When AppKit will not mint the field.
     */
    protected function mintLabel(string $name, string $text): Label
    {
        $field = NSTextField::labelWithString($text);
        if (! $field instanceof NSTextField) {
            throw AppKitWindowException::viewMintFailed($name);
        }

        $this->content->addSubview($field->handle);

        return new AppKitLabel($name, $this, $text, $field);
    }

    /**
     * A momentary push button, minted with no target — the AppKitButton
     * wires the bridge's shared action target to fireClick() itself.
     * @throws AppKitWindowException When AppKit will not mint the button.
     */
    protected function mintButton(string $name, string $label): Button
    {
        $button = NSButton::buttonWithTitleTargetAction($label, 0, '');
        if (! $button instanceof NSButton) {
            throw AppKitWindowException::viewMintFailed($name);
        }

        $this->content->addSubview($button->handle);

        return new AppKitButton($name, $this, $label, $button);
    }

    /**
     * An indeterminate spinner in the spinning style, hidden while stopped
     * so a stopped spinner costs nothing visually.
     * @throws AppKitWindowException When AppKit will not mint the indicator.
     */
    protected function mintSpinner(string $name): Spinner
    {
        $indicator = NSProgressIndicator::initWithFrame(new NSRect(0.0, 0.0, 32.0, 32.0));
        if (! $indicator instanceof NSProgressIndicator) {
            throw AppKitWindowException::viewMintFailed($name);
        }

        $indicator->setStyle(NSProgressIndicatorStyle::SPINNING);
        $indicator->setIndeterminate(true);
        $indicator->setDisplayedWhenStopped(false);

        $this->content->addSubview($indicator->handle);

        return new AppKitSpinner($name, $this, $indicator);
    }

    /**
     * An NSImageView scaling proportionally up or down. A null path mints
     * an empty NSImage to swap out through setPath() later.
     * @throws AppKitWindowException When AppKit will not read the file or mint the view.
     */
    protected function mintImage(string $name, ?string $path): Image
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

        $this->content->addSubview($view->handle);

        return new AppKitImage($name, $this, $path, $view);
    }

    /**
     * An AVPlayerView with inline native controls; the AppKitVideo mints
     * an AVPlayer per path itself. Placed by Windowable::video().
     * @throws AppKitWindowException When AVKit will not mint the view.
     */
    protected function mintVideo(string $name, ?string $path): Video
    {
        $view = AVPlayerView::initWithFrame(new NSRect(0.0, 0.0, 320.0, 240.0));
        if (! $view instanceof AVPlayerView) {
            throw AppKitWindowException::viewMintFailed($name);
        }

        $view->setControlsStyle(AVPlayerViewControlsStyle::INLINE);

        $this->content->addSubview($view->handle);

        $video = new AppKitVideo($name, $this, null, $view);
        if (! is_null($path)) {
            $video->setPath($path);
        }

        return $video;
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
