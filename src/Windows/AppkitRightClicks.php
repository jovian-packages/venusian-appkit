<?php

namespace Jovian\Toolkits\Appkit\Windows;

use Jovian\Toolkits\Appkit\Bridge\AppkitSession;
use NSEvent;
use NSEventModifierFlags;
use NSEventType;
use NSPoint;
use NSView;
use Surface\Contracts\HumanInput\InputTap;
use Surface\Contracts\Windows\Mail\View\RightClicked;
use Surface\Contracts\Windows\Mail\WindowRightClicked;
use Surface\Contracts\Windows\Primitives\TKPrimitive;
use Surface\Windows\Menus\ContextMenu;
use WeakReference;

/**
 * A toolkit window's right clicks as mail, from the events the session pumps (a tap on the
 * session, which shows each NSEvent before it is dispatched): a right-button press, or a primary
 * press with Control (Ctrl-click; a two-finger or Magic Mouse right click arrives as the right
 * button). Points are top-left, as on the other toolkits, whatever a view's flipping. After the
 * mail, the context menu of the primitive or its nearest group that has one opens at the press, and
 * the session does not dispatch the press, so the view's own menu stays shut. AppKit tracks the menu
 * until it closes; the sketch's loop waits meanwhile, as it does while the menu bar is open.
 */
final class AppkitRightClicks implements InputTap
{
    private bool $watching = true;

    /** @var WeakReference<AppkitWindow> the window holds this; a strong reference back would keep a closed window, and its NSWindow, alive until PHP's cycle collector runs */
    private readonly WeakReference $window;

    /** The context menu opened last. */
    private ?AppkitContextMenu $menu = null;

    public function __construct(
        AppkitWindow $window,
        private readonly AppkitSession $session,
    ) {
        $this->window = WeakReference::create($window);
        $session->tap($this);
    }

    public function watching(): bool
    {
        return $this->watching;
    }

    /** The window closed: stop seeing the session's events. */
    public function detach(): void
    {
        $this->session->untap($this);
        $this->watching = false;
    }

    public function see(object $native_event): void
    {
        $window = $this->window->get();
        if (is_null($window) || ! $window->isOpen()) {
            $this->detach();

            return;
        }
        if (! $native_event instanceof NSEvent || $native_event->windowNumber() !== $window->native()->windowNumber()) {
            return;
        }
        $type = $native_event->type();
        $control = ($native_event->modifierFlags() & NSEventModifierFlags::CONTROL->value) !== 0;
        if (($type === NSEventType::RIGHT_MOUSE_DOWN || ($type === NSEventType::LEFT_MOUSE_DOWN && $control))
            && $this->at($window, $native_event->locationInWindow())) {
            $this->session->withhold($native_event);
        }
    }

    /** The context menu opened last, closed since. */
    public function openMenu(): ?AppkitContextMenu
    {
        return $this->menu;
    }

    /**
     * A right click at a window (base) point: mail for the innermost primitive there, in its own
     * coordinates; else for the window's bare content; nothing outside the content (the title bar)
     * or when the primitive or one of its groups is disabled. Then the context menu of the primitive
     * or its nearest group opens there, until it closes. Whether a menu opened.
     */
    private function at(AppkitWindow $window, NSPoint $point): bool
    {
        $content = $window->native()->contentView();
        if (is_null($content)) {
            return false;
        }
        $owners = [];
        foreach ($window->registry()->all() as $primitive) {
            foreach (['native', 'placedView'] as $view) {
                if (method_exists($primitive, $view)) {
                    $owners[spl_object_id($primitive->{$view}())] = $primitive;
                }
            }
        }

        $frame = $content->superview();
        $hit = $content->hitTest(is_null($frame) ? $point : $frame->convertPointFromView($point, null));
        for ($view = $hit; ! is_null($view); $view = $view->superview()) {
            $primitive = $owners[spl_object_id($view)] ?? null;
            if ($primitive instanceof TKPrimitive) {
                $menu = null;
                $owner = null;
                for ($at = $primitive; ! is_null($at); $at = $at->parent()) {
                    if (! $at->isEnabled()) {
                        return false;
                    }
                    if (is_null($menu) && ! is_null($at->contextMenu())) {
                        [$menu, $owner] = [$at->contextMenu(), $at];
                    }
                }
                [$x, $y] = self::topLeft($view, $point);
                $this->session->post(new RightClicked($window->name(), $primitive->path(), $primitive->uuid(), $x, $y));
                if (! $menu instanceof ContextMenu) {
                    return false;
                }
                // The owner keeps the user's toggle and radio choices: the next open shows them.
                $this->menu = new AppkitContextMenu($menu, $window->name(), $this->session, null, $owner);
                $this->menu->popupAt($view, $view->convertPointFromView($point, null));

                return true;
            }
            if ($view === $content) {
                [$x, $y] = self::topLeft($content, $point);
                $this->session->post(new WindowRightClicked($window->name(), $x, $y));

                return false;
            }
        }

        return false;
    }

    /** @return array{float, float} a window point in $view, measured from its top-left */
    public static function topLeft(NSView $view, NSPoint $point): array
    {
        $local = $view->convertPointFromView($point, null);

        return [$local->x, $view->isFlipped() ? $local->y : $view->bounds()->height - $local->y];
    }
}
