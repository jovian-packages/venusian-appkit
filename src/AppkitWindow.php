<?php

namespace Jovian\Toolkits\Appkit;

use NSAlert;
use NSAlertStyle;
use NSSavePanel;
use NSBackingStoreType;
use NSControlStateValue;
use NSURL;
use NSWorkspace;
use NSLayoutConstraint;
use NSRect;
use NSWindow;
use NSWindowStyleMask;
use ObjCDelegate;
use ObjCKeyWindow;
use RuntimeException;
use Surface\Contracts\Toolkits\Mail\Windows\WindowFocused;
use Surface\Contracts\Toolkits\Mail\Windows\WindowScaleChanged;
use Surface\Toolkits\ToolkitWindow;
use Surface\Contracts\Toolkits\Dialogs\Alert;
use Surface\Contracts\Toolkits\Dialogs\AlertStyle;
use Surface\Contracts\Toolkits\Dialogs\SaveRequest;
use Surface\Contracts\Toolkits\UI\TKColumn as ColumnContract;

class AppkitWindow extends ToolkitWindow
{
    protected bool $key = false;

    /**
     * The native window while open; null once closed.
     * @var ObjCKeyWindow|null
     */
    protected ?NSWindow $window = null;

    /**
     * Hears the window's key and close notifications. AppKit holds delegates weakly: kept here.
     * @var ObjCDelegate|null
     */
    protected ?ObjCDelegate $delegate = null;

    /** The window's focus as primitives: compared after each event the session's pump takes. */
    //protected ?AppkitFocus $focus = null;

    /**
     * The four edges pinning the content container to the content view.
     * @var list<NSLayoutConstraint>
     */
    protected array $content_constraints = [];

    /**
     * A child: whether its sheet began on the parent.
     */
    protected bool $sheet_begun = false;

    /** The backing scale last posted, or seen at open. */
    private ?float $backing_scale = null;

    /** The NSAlert shown as a sheet on the window, until it is answered. */
    protected ?NSAlert $alert = null;

    /** The NSSavePanel shown as a sheet on the window, until it is answered. */
    protected ?NSSavePanel $save = null;

    public function __construct(
        string $name,
        AppkitSession $session,
        AppkitAdapterDriver $driver,
        int $width,
        int $height,
        ?AppkitWindow $parent = null,
    ) {
        parent::__construct($name, $session, $driver, $width, $height, $parent);
    }

    /**
     * The driver this window was opened by.
     */
    public function adapter(): AppkitAdapterDriver
    {
        /** @var AppkitAdapterDriver */
        return $this->adapter;
    }

    /**
     * The content area in points: what the content container is laid out in.
     * @return array{int, int}
     * @throws RuntimeException Once closed.
     */
    public function size(): array
    {
        $frame = $this->live()->contentView()->frame();

        return [(int) round($frame->width), (int) round($frame->height)];
    }

    public function isOpen(): bool
    {
        return ! is_null($this->window);
    }

    /**
     * The native window, for engines that draw into it.
     * @throws RuntimeException Once closed.
     */
    public function native(): NSWindow
    {
        return $this->live();
    }

    public function present(): static
    {
        $window = $this->live();
        $show = function () use ($window): void {
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

            $this->session->application()->get()->activateIgnoringOtherApps(true);
            $window->makeKeyAndOrderFront(null);
            $this->focusInitial();
        };

        $show();
        return $this;
    }

    public function setTitle(string $title): static
    {
        $this->live()->setTitle($title);

        return $this;
    }

    protected function live(): NSWindow
    {
        return $this->window ?? throw new RuntimeException("Window '{$this->name}' is closed.");
    }

    protected function bootstrapWindow(int $width, int $height): void
    {
        $mask = NSWindowStyleMask::TITLED->value | NSWindowStyleMask::CLOSABLE->value
            | NSWindowStyleMask::MINIATURIZABLE->value | NSWindowStyleMask::RESIZABLE->value;

        $this->window = ObjCKeyWindow::initWithContentRectStyleMaskBackingDefer(
            new NSRect(0.0, 0.0, (float) $width, (float) $height),
            $mask,
            NSBackingStoreType::BUFFERED,
            false,
        );

        $this->window->setReleasedWhenClosed(false);
        $this->window->setTitle($this->name);
        $this->window->center();
        // Tab and Shift-Tab walk the views in their on-screen order.
        $this->window->setAutorecalculatesKeyViewLoop(true);

        $this->delegate = new ObjCDelegate('NSWindowDelegate');
        $this->delegate->on('windowDidBecomeKey:', fn () => $this->becameKey());
        $this->delegate->on('windowDidResignKey:', fn () => $this->resignedKey());
        $this->delegate->on('windowShouldClose:', fn (): bool => $this->shouldClose());
        $this->delegate->on('windowWillClose:', fn () => $this->closed());
        $this->delegate->on('windowDidResize:', fn () => $this->didResize());
        $this->delegate->on('windowDidChangeBackingProperties:', fn () => $this->backingChanged());
        $this->backing_scale = $this->window->backingScaleFactor();
        $this->window->setDelegate($this->delegate);

        //$this->focus = new AppkitFocus($this, $this->window, $this->session);

        // Not ported yet (deprecated/Windows): the right-click, group-click, button-key and window-key
        // taps. A canvas's clicks, pointer, hover and keys are its input now (topside HumanInput).
    }

    protected function becameKey(): void
    {
        $this->key = true;
        //$this->focus?->becameKey();
        // AppKit has no pasteboard notification: another app's copy shows when the app comes back.
        //$this->adapter->clipboard()->compare();

        // AppKit has one main menu and does not change it by itself. Here and not from mail: a
        // shortcut is matched against whichever bar is installed at that instant.
        $this->gainedFocus();

        $this->session->post(new WindowFocused($this->name));
    }

    protected function resignedKey(): void
    {
        $this->key = false;
        // The default bar, until a window's becameKey() shows that window's.
        $this->lostFocus();
    }

    /** The delegate's windowDidResize: the content area's new size. */
    protected function didResize(): void
    {
        [$width, $height] = $this->size();
        $this->resized($width, $height);
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

    /**
     * The content container's view fills the content view: pinned to its four edges.
     */
    protected function mountContent(ColumnContract $content): void
    {
        $view = $content->native();
        $area = $this->live()->contentView();
        $view->setTranslatesAutoresizingMaskIntoConstraints(false);
        $area->addSubview($view);

        $this->content_constraints = [
            $view->leadingAnchor()->constraintEqualToAnchor($area->leadingAnchor()),
            $view->trailingAnchor()->constraintEqualToAnchor($area->trailingAnchor()),
            $view->topAnchor()->constraintEqualToAnchor($area->topAnchor()),
            $view->bottomAnchor()->constraintEqualToAnchor($area->bottomAnchor()),
        ];
        NSLayoutConstraint::activateConstraints($this->content_constraints);
    }

    /**
     * Its pins go, and the view leaves the content view.
     */
    protected function unmountContent(ColumnContract $content): void
    {
        NSLayoutConstraint::deactivateConstraints($this->content_constraints);
        $this->content_constraints = [];
        $content->native()->removeFromSuperview();
    }

    /**
     * AppKit's close sends windowWillClose:, which takes the close path. It skips
     * windowShouldClose:, so a confirming window closes too.
     */
    protected function closeNative(): void
    {
        $this->live()->close();
    }

    protected function releaseNative(): void
    {
        //$this->focus?->detach();
        $this->content_constraints = [];
        // The NSWindow can outlive this object: its delegate must not call into a window that is gone.
        $this->window->setDelegate(null);
        $this->window = null;
        $this->key = false;
    }

    /**
     * An NSAlert, as a sheet on the window. The buttons go in in order, so the first is the
     * rightmost. AppKit's key equivalents say which button Return and Esc press; one button
     * holds one, so when the default is also the cancel button Return presses it and Esc
     * presses none, as in every AppKit alert. No title: a sheet has none.
     */
    protected function showAlert(Alert $alert): void
    {
        $native = NSAlert::init();
        $native->setMessageText($alert->message);
        $native->setInformativeText($alert->detail ?? '');
        if (! is_null($alert->style)) {
            $native->setAlertStyle(match ($alert->style) {
                AlertStyle::Info => NSAlertStyle::INFORMATIONAL,
                AlertStyle::Warning => NSAlertStyle::WARNING,
                AlertStyle::Critical => NSAlertStyle::CRITICAL,
            });
        }
        if (! is_null($alert->suppression)) {
            $native->setShowsSuppressionButton(true);
            $native->suppressionButton()?->setTitle($alert->suppression);
        }
        foreach ($alert->buttons as $index => $label) {
            // Set on every button: AppKit gives Return to the first and Esc to one titled "Cancel".
            $native->addButtonWithTitle($label)->setKeyEquivalent(match (true) {
                $index === $alert->default => "\r",
                $index === $alert->cancel => "\e",
                default => '',
            });
        }

        $this->alert = $native;
        $native->beginSheetModalForWindowCompletionHandler($this->live(), function (int $code) use ($native): void {
            if ($this->alert !== $native) {
                return;
            }
            $this->alert = null;
            $this->alertAnswered(
                $code - NSAlertFirstButtonReturn,
                $native->showsSuppressionButton() && $native->suppressionButton()?->state() === NSControlStateValue::ON,
            );
        });
    }

    /**
     * An NSSavePanel, as a sheet on the window. The name is the panel's name field, the
     * directory its directoryURL, the title its message (a sheet has no title bar). AppKit asks
     * itself before replacing a file. The chosen path is the panel's URL when it ends with OK.
     */
    protected function showSave(SaveRequest $request): void
    {
        $panel = NSSavePanel::savePanel();
        if (! is_null($request->name)) {
            $panel->setNameFieldStringValue($request->name);
        }
        if (! is_null($request->title)) {
            $panel->setMessage($request->title);
        }
        if (! is_null($request->directory)) {
            $panel->setDirectoryURL(NSURL::fileURLWithPath($request->directory));
        }

        $this->save = $panel;
        $panel->beginSheetModalForWindowCompletionHandler($this->live(), function (int $result) use ($panel): void {
            if ($this->save !== $panel) {
                return;
            }
            $this->save = null;
            $this->saveAnswered($result === NSModalResponseOK ? $panel->URL()?->path() : null);
        });
    }

    /** NSWorkspace opens it with the user's browser. */
    protected function openUrl(string $url): void
    {
        $address = NSURL::URLWithString($url);
        if (! is_null($address)) {
            NSWorkspace::sharedWorkspace()->openURL($address);
        }
    }

    /** The sheet ends before the window closes; its completion handler then finds it gone. */
    protected function closeAlert(): void
    {
        $native = $this->alert;
        $this->alert = null;
        if (! is_null($native) && ! is_null($this->window)) {
            $this->window->endSheetReturnCode($native->window(), NSModalResponseCancel);
        }
    }

    /** The sheet ends before the window closes; its completion handler then finds it gone. */
    protected function closeSave(): void
    {
        $panel = $this->save;
        $this->save = null;
        if (! is_null($panel) && ! is_null($this->window)) {
            $this->window->endSheetReturnCode($panel, NSModalResponseCancel);
        }
    }

    /** The close button: AppKit closes the window unless it confirms close; then the app decides. */
    protected function shouldClose(): bool
    {
        // A parent with an open child or dialog keeps the user's close silently: AppKit blocks it only once the sheet begins.
        /*if (! is_null(ChildWindows::childOf($this->driver, $this)) || ! is_null($this->driver->dialogs()->openOn($this))) {
            return false;
        }*/

        return $this->allowsUserClose();
    }
}
