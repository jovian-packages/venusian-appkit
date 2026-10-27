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
use ObjCKeyWindow;
use Surface\Contracts\Windows\Mail\WindowCloseRequested;
use Surface\Contracts\Windows\Mail\WindowClosed;
use Surface\Contracts\Windows\Mail\WindowFocused;
use Surface\Contracts\Windows\Mail\WindowResized;
use Surface\Contracts\Windows\Mail\WindowScaleChanged;
use Surface\Contracts\Windows\Primitives\TKPrimitive;
use Surface\Contracts\Windows\Primitives\TKPrimitiveGroup;
use Surface\Contracts\Windows\ToolkitWindow;
use Surface\Contracts\Windows\WindowException;
use Surface\Windows\ChildWindows;
use Surface\Windows\Menus\MenuProfile;
use Surface\Windows\Primitives\HostsPrimitives;
use WeakReference;

class AppkitWindow implements ToolkitWindow
{
    use HostsPrimitives;

    /**
     * The native window while open; null once closed.
     * @var ObjCKeyWindow|null
     */
    protected ?ObjCKeyWindow $window;

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

    protected AppkitRightClicks $right_clicks;

    protected AppkitCanvasClicks $canvas_clicks;

    protected AppkitCanvasPointer $canvas_pointer;

    protected AppkitCanvasHover $canvas_hover;

    /** The backing scale last posted, or seen at open. */
    private float $backing_scale;

    protected AppkitButtonKeys $button_keys;

    protected AppkitFocus $focus;

    protected AppkitWindowKeys $window_keys;

    /** @var WeakReference<TKPrimitive>|null focused on each present(); weak, so a removed primitive is freed */
    protected ?WeakReference $initial_focus = null;

    protected bool $confirming = false;

    /** A child: whether its sheet began on the parent. */
    protected bool $sheet_begun = false;

    public function __construct(
        protected readonly string $name,
        protected readonly AppkitSession $session,
        protected readonly AppkitBridgeDriver $driver,
        int $width,
        int $height,
        ?MenuProfile $menu,
        protected readonly ?ToolkitWindow $parent = null,
    ) {
        // The key window: its keyDown:, reached only for keys no responder took, posts WindowKeyPressed.
        $this->window = ObjCKeyWindow::initWithContentRectStyleMaskBackingDefer(
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
        // Tab and Shift-Tab walk the views in their on-screen order.
        $this->window->setAutorecalculatesKeyViewLoop(true);

        $this->delegate = new ObjCDelegate('NSWindowDelegate');
        $this->delegate->on('windowDidBecomeKey:', fn () => $this->becameKey());
        $this->delegate->on('windowDidResignKey:', fn () => $this->resignedKey());
        $this->delegate->on('windowShouldClose:', fn (): bool => $this->shouldClose());
        $this->delegate->on('windowWillClose:', fn () => $this->closed());
        $this->delegate->on('windowDidResize:', fn () => $this->resized());
        $this->delegate->on('windowDidChangeBackingProperties:', fn () => $this->backingChanged());
        $this->backing_scale = $this->window->backingScaleFactor();
        $this->window->setDelegate($this->delegate);
        $this->right_clicks = new AppkitRightClicks($this, $session);
        $this->canvas_clicks = new AppkitCanvasClicks($this, $session);
        // Tapped after the right clicks: a press their menu withheld arrives here already withheld.
        $this->canvas_pointer = new AppkitCanvasPointer($this, $session);
        // Tapped after the pointer: it sees a release after the pointer posted its CanvasReleased.
        $this->canvas_hover = new AppkitCanvasHover($this, $session);
        $this->button_keys = new AppkitButtonKeys($this, $session);
        $this->focus = new AppkitFocus($this, $this->window, $session);
        $this->window_keys = new AppkitWindowKeys($this, $this->window);

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
     * A child is a sheet: the parent shows first, then the sheet begins on it, one time.
     * Showing makes the initial focus primitive first responder, or AppKit's first key view:
     * present() posts no focus mail.
     * @return $this
     */
    public function present(): static
    {
        $window = $this->live();
        $initial = $this->initial_focus?->get();
        $initial = ! is_null($initial) && ! $initial->isRemoved() && method_exists($initial, 'focusView') ? $initial->focusView() : null;
        $window->setInitialFirstResponder($initial);
        $this->focus->quietly(function () use ($window, $initial): void {
            if ($this->parent instanceof self) {
                $parent = $this->parent->native();
                if (! $parent->isVisible()) {
                    $this->parent->present();
                }
                if (! $this->sheet_begun) {
                    $this->sheet_begun = true;
                    $parent->beginSheetCompletionHandler($window, null);

                    return;
                }
            }
            $this->session->application()->activateIgnoringOtherApps(true);
            $window->makeKeyAndOrderFront(null);
            // AppKit may have made it first responder already: again would end its editing and select it anew.
            if (! is_null($initial) && $this->focus->holder() !== $this->initial_focus?->get()) {
                $window->makeFirstResponder($initial);
            }
        });
        $this->focus->presented();

        return $this;
    }

    public function focused(): ?TKPrimitive
    {
        return $this->focus->holder();
    }

    /**
     * @throws WindowException When $primitive belongs to another window.
     */
    public function setInitialFocus(?TKPrimitive $primitive): static
    {
        if (! is_null($primitive) && $primitive->window() !== $this) {
            throw new WindowException("'{$primitive->path()}' is not in window '{$this->name}'.");
        }
        $this->initial_focus = is_null($primitive) ? null : WeakReference::create($primitive);

        return $this;
    }

    /** The window's focus as primitives; primitives move focus through it. */
    public function focusTracker(): AppkitFocus
    {
        return $this->focus;
    }

    public function parent(): ?ToolkitWindow
    {
        return $this->parent;
    }

    public function confirmClose(bool $on = true): static
    {
        $this->confirming = $on;

        return $this;
    }

    public function isConfirmingClose(): bool
    {
        return $this->confirming;
    }

    /**
     * The user's close: the close button (windowShouldClose:), or Esc in a child with no cancel
     * button. While confirming, WindowCloseRequested and the window stays open.
     * @return void
     */
    public function userClose(): void
    {
        if ($this->shouldClose()) {
            $this->close();
        }
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
     * the same path as the user's close button. The child closes first; a child ends its sheet
     * first. NSWindow::close() does not ask windowShouldClose:, so a confirming window closes too.
     * @return void
     */
    public function close(): void
    {
        if (is_null($this->window)) {
            return;
        }
        ChildWindows::childOf($this->driver, $this)?->close();
        $this->driver->dialogs()->closeOn($this);
        if ($this->sheet_begun && $this->parent instanceof self && $this->parent->isOpen()) {
            $this->parent->native()->endSheet($this->window);
        }
        $this->window?->close();
    }

    public function setMenuBar(string $profile): static
    {
        $this->live();
        ChildWindows::refuseMenuBar($this);
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

    public function setItemEnabled(string $item, bool $enabled): static
    {
        $this->menuOrFail()->setItemEnabled($item, $enabled);

        return $this;
    }

    public function isItemEnabled(string $item): bool
    {
        return $this->menuOrFail()->isItemEnabled($item);
    }

    public function setRadio(string $group, string $item): static
    {
        $this->menuOrFail()->setRadio($group, $item);

        return $this;
    }

    public function radio(string $group): string
    {
        return $this->menuOrFail()->radio($group);
    }

    public function setItemLabel(string $item, string $label): static
    {
        $this->menuOrFail()->setItemLabel($item, $label);

        return $this;
    }

    public function itemLabel(string $item): string
    {
        return $this->menuOrFail()->itemLabel($item);
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

    /** The window's right clicks, posted as mail. */
    public function rightClicks(): AppkitRightClicks
    {
        return $this->right_clicks;
    }

    /** The window's canvas clicks, posted as mail. */
    public function canvasClicks(): AppkitCanvasClicks
    {
        return $this->canvas_clicks;
    }

    /** The window's canvas presses, drags, releases and wheels, posted as mail. */
    public function canvasHover(): AppkitCanvasHover
    {
        return $this->canvas_hover;
    }

    /**
     * The delegate's windowDidChangeBackingProperties: (another display, a display setting): posts
     * WindowScaleChanged when the backing scale differs from the last posted.
     */
    public function backingChanged(): void
    {
        $scale = $this->window->backingScaleFactor();
        if ($scale === $this->backing_scale) {
            return;
        }
        $this->backing_scale = $scale;
        $this->session->post(new WindowScaleChanged($this->name, $scale));
    }

    public function canvasPointer(): AppkitCanvasPointer
    {
        return $this->canvas_pointer;
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
        $this->focus->becameKey();
        // AppKit has no pasteboard notification: another app's copy shows when the app comes back.
        $this->driver->clipboard()->compare();

        if (! is_null($this->parent)) {
            // A child has no bar: the default bar shows, blocked, so the parent's input stops there too.
            $this->driver->installDefaultMenuBar(true);
        } elseif (is_null($this->menu)) {
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

        $this->right_clicks->detach();
        $this->canvas_clicks->detach();
        $this->canvas_pointer->detach();
        $this->canvas_hover->detach();
        $this->button_keys->detach();
        $this->focus->detach();
        $this->window_keys->detach();
        $this->removeContent();
        $this->content_constraints = [];
        $this->session->forgetLatest("window.resized.{$this->name}");
        $this->window = null;
        $this->key = false;
        $this->session->post(new WindowClosed($this->name));
        $this->driver->forget($this->name);
    }

    /** The close button: AppKit closes the window unless it confirms close; then the app decides. */
    protected function shouldClose(): bool
    {
        // A parent with an open child or dialog keeps the user's close silently: AppKit blocks it only once the sheet begins.
        if (! is_null(ChildWindows::childOf($this->driver, $this)) || ! is_null($this->driver->dialogs()->openOn($this))) {
            return false;
        }
        if ($this->confirming) {
            $this->session->post(new WindowCloseRequested($this->name));

            return false;
        }

        return true;
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
