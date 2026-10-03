<?php

namespace Jovian\Toolkits\Appkit\Windows;

use Jovian\Toolkits\Appkit\Bridge\AppkitBridgeDriver;
use Jovian\Toolkits\Appkit\Bridge\AppkitSession;
use Jovian\Toolkits\Appkit\Primitives\AppkitPrimitiveFactory;
use NSBackingStoreType;
use NSLayoutConstraint;
use NSRect;
use NSWindow;
use NSWindowStyleMask;
use ObjCDelegate;
use Surface\Contracts\Windows\Mail\WindowClosed;
use Surface\Contracts\Windows\Mail\WindowFocused;
use Surface\Contracts\Windows\Mail\WindowResized;
use Surface\Contracts\Windows\Primitives\TKPrimitiveGroup;
use Surface\Contracts\Windows\ToolkitWindow;
use Surface\Contracts\Windows\WindowException;
use Surface\Windows\Menus\MenuProfile;
use Surface\Windows\Primitives\HostsPrimitives;

class AppkitWindow implements ToolkitWindow
{
    use HostsPrimitives;

    /**
     * The native window while open; null once closed.
     * @var NSWindow|null
     */
    protected ?NSWindow $window;

    /**
     * Hears the window's key and close notifications. AppKit holds delegates weakly: kept here.
     * @var ObjCDelegate
     */
    protected ObjCDelegate $delegate;

    /**
     * This window's bar, installed as the app's bar while the window is key.
     * @var AppkitMenuBar|null
     */
    protected ?AppkitMenuBar $menu = null;

    protected bool $key = false;

    protected ?AppkitPrimitiveFactory $factory = null;

    /**
     * The four edges pinning the content container to the content view.
     * @var list<NSLayoutConstraint>
     */
    protected array $content_constraints = [];

    public function __construct(
        protected readonly string $name,
        protected readonly AppkitSession $session,
        protected readonly AppkitBridgeDriver $driver,
        int $width,
        int $height,
        ?MenuProfile $menu,
    ) {
        $this->window = NSWindow::initWithContentRectStyleMaskBackingDefer(
            new NSRect(0.0, 0.0, (float) $width, (float) $height),
            NSWindowStyleMask::TITLED->value | NSWindowStyleMask::CLOSABLE->value
                | NSWindowStyleMask::MINIATURIZABLE->value | NSWindowStyleMask::RESIZABLE->value,
            NSBackingStoreType::BUFFERED,
            false,
        );
        // The PHP object holds the window; AppKit must not release it a second time on close.
        $this->window->setReleasedWhenClosed(false);
        $this->window->setTitle($name);
        $this->window->center();

        $this->delegate = new ObjCDelegate('NSWindowDelegate');
        $this->delegate->on('windowDidBecomeKey:', fn () => $this->becameKey());
        $this->delegate->on('windowDidResignKey:', fn () => $this->resignedKey());
        $this->delegate->on('windowWillClose:', fn () => $this->closed());
        $this->delegate->on('windowDidResize:', fn () => $this->resized());
        $this->window->setDelegate($this->delegate);

        if (! is_null($menu)) {
            $this->menu = new AppkitMenuBar($session, $menu, $name, $driver->about());
        }
    }

    public function name(): string
    {
        return $this->name;
    }

    public function title(): string
    {
        return $this->live()->title();
    }

    public function setTitle(string $title): static
    {
        $this->live()->setTitle($title);

        return $this;
    }

    /**
     * Show and focus: the app takes focus too, since a window of an inactive app is never key.
     * @return $this
     */
    public function present(): static
    {
        $window = $this->live();
        $this->session->application()->activateIgnoringOtherApps(true);
        $window->makeKeyAndOrderFront(null);

        return $this;
    }

    public function isOpen(): bool
    {
        return ! is_null($this->window);
    }

    /**
     * Whether this window is key, and so whose bar the app shows.
     * @return bool
     */
    public function isKey(): bool
    {
        return $this->key;
    }

    /**
     * AppKit runs windowWillClose: inside close, so the mail and bookkeeping happen there,
     * the same path as the user's close button.
     * @return void
     */
    public function close(): void
    {
        $this->window?->close();
    }

    public function setMenuBar(string $profile): static
    {
        $this->live();
        $this->menu = new AppkitMenuBar($this->session, $this->driver->profile($profile), $this->name, $this->driver->about());

        if ($this->key) {
            $this->menu->install();
        }

        return $this;
    }

    public function setToggle(string $item, bool $on): static
    {
        $this->menuOrFail()->setToggle($item, $on);

        return $this;
    }

    public function isToggled(string $item): bool
    {
        return $this->menuOrFail()->isToggled($item);
    }

    /**
     * The content area in points: what the content container is laid out in.
     * @return array{int, int}
     * @throws WindowException Once closed.
     */
    public function size(): array
    {
        $frame = $this->live()->contentView()->frame();

        return [(int) round($frame->width), (int) round($frame->height)];
    }

    /**
     * This window's minter, one per window.
     * @return AppkitPrimitiveFactory
     */
    public function factory(): AppkitPrimitiveFactory
    {
        return $this->factory ??= new AppkitPrimitiveFactory($this);
    }

    /**
     * The session primitives post their mail through.
     * @return AppkitSession
     */
    public function session(): AppkitSession
    {
        return $this->session;
    }

    /**
     * Pin the content container's placed view to the four edges of the content view.
     *
     * @param TKPrimitiveGroup $content
     * @return void
     */
    protected function mountContent(TKPrimitiveGroup $content): void
    {
        $view = $content->placedView();
        $area = $this->live()->contentView();
        $area->addSubview($view);
        $this->content_constraints = [
            $view->leadingAnchor()->constraintEqualToAnchor($area->leadingAnchor()),
            $view->trailingAnchor()->constraintEqualToAnchor($area->trailingAnchor()),
            $view->topAnchor()->constraintEqualToAnchor($area->topAnchor()),
            $view->bottomAnchor()->constraintEqualToAnchor($area->bottomAnchor()),
        ];
        NSLayoutConstraint::activateConstraints($this->content_constraints);
        $content->syncNativeEnabled();
    }

    /**
     * The native window, for engines that draw into it.
     * @return NSWindow
     * @throws WindowException Once closed.
     */
    public function native(): NSWindow
    {
        return $this->live();
    }

    /**
     * This window's bar, or null when it has none.
     * @return AppkitMenuBar|null
     */
    public function menuBar(): ?AppkitMenuBar
    {
        return $this->menu;
    }

    protected function becameKey(): void
    {
        $this->key = true;

        if (is_null($this->menu)) {
            $this->driver->installDefaultMenuBar();
        } else {
            $this->menu->install();
        }

        $this->session->post(new WindowFocused($this->name));
    }

    protected function resignedKey(): void
    {
        $this->key = false;
        // The next key window installs its own bar right after; with none, the app's bar stands.
        $this->driver->showDefaultMenuBar();
    }

    /**
     * Live resizes collapse to the last size per pump.
     * @return void
     */
    protected function resized(): void
    {
        [$width, $height] = $this->size();
        $this->session->postLatest("window.resized.{$this->name}", new WindowResized($this->name, $width, $height));
    }

    /**
     * The one close path, while the native window still lives: the content tree goes first,
     * then any resize not yet flushed, then the window is gone, posted and forgotten.
     * @return void
     */
    protected function closed(): void
    {
        if (is_null($this->window)) {
            return;
        }

        $this->removeContent();
        $this->content_constraints = [];
        $this->session->forgetLatest("window.resized.{$this->name}");
        $this->window = null;
        $this->key = false;
        $this->session->post(new WindowClosed($this->name));
        $this->driver->forget($this->name);
    }

    protected function live(): NSWindow
    {
        return $this->window ?? throw new WindowException("Window '{$this->name}' is closed.");
    }

    protected function menuOrFail(): AppkitMenuBar
    {
        return $this->menu ?? throw new WindowException("Window '{$this->name}' has no menu bar.");
    }
}
