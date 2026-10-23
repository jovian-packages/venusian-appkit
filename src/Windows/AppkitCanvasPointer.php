<?php

namespace Jovian\Toolkits\Appkit\Windows;

use Jovian\Toolkits\Appkit\Bridge\AppkitSession;
use Jovian\Toolkits\Appkit\Input\AppkitInputEngine;
use NSEvent;
use NSEventType;
use NSPoint;
use NSView;
use Surface\Contracts\HumanInput\InputTap;
use Surface\Contracts\HumanInput\Modifiers;
use Surface\Contracts\HumanInput\MouseButton;
use Surface\Contracts\Windows\Mail\View\CanvasDragged;
use Surface\Contracts\Windows\Mail\View\CanvasPressed;
use Surface\Contracts\Windows\Mail\View\CanvasReleased;
use Surface\Contracts\Windows\Mail\View\CanvasWheeled;
use Surface\Contracts\Windows\Primitives\TKCanvas;
use WeakReference;

/**
 * A toolkit window's canvas presses, drags, releases and wheels as mail, from the events the session
 * pumps (a tap on the session, beside AppkitCanvasClicks).
 *
 * The first button down on a watching, enabled canvas owns the press: other buttons' presses and
 * releases post nothing until it ends, and AppKit sends its drags and its release to this window
 * wherever the pointer goes. Exactly one CanvasReleased follows every CanvasPressed: at the owning
 * button's release, or at once at the last point posted when a context menu opens. AppkitRightClicks
 * taps first and its menu runs before this tap sees the press, withholding it, so a withheld press
 * ends the press it starts or the one owned. A press the code ends — watchPointer(false), the canvas
 * or a group above it disabled, the canvas removed, the window closed — drops silently.
 *
 * A wheel over a watching, enabled canvas posts and is withheld from dispatch, so an enclosing
 * scroll view never scrolls with it.
 */
final class AppkitCanvasPointer implements InputTap
{
    private bool $watching = true;

    /** @var WeakReference<AppkitWindow> the window holds this; a strong reference back would keep a closed window alive */
    private readonly WeakReference $window;

    /** @var array{TKCanvas, NSView, MouseButton, float, float, Modifiers}|null the press owned: canvas, its view, button, last canvas point, modifiers last seen */
    private ?array $press = null;

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
        $this->press = null;
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
        $this->seen($native_event, $native_event->locationInWindow());
    }

    /**
     * An event of this window at $point, in its window (base) coordinates: what see() hands on once the
     * event is this window's. An NSEvent made from a CGEvent reads window number 0, so tests call this.
     */
    public function seen(NSEvent $event, NSPoint $point): void
    {
        $window = $this->window->get();
        if (is_null($window) || ! $this->watching) {
            return;
        }
        match ($event->type()) {
            NSEventType::LEFT_MOUSE_DOWN, NSEventType::RIGHT_MOUSE_DOWN, NSEventType::OTHER_MOUSE_DOWN => $this->pressed($window, $event, $point),
            NSEventType::LEFT_MOUSE_DRAGGED, NSEventType::RIGHT_MOUSE_DRAGGED, NSEventType::OTHER_MOUSE_DRAGGED => $this->dragged($event, $point),
            NSEventType::LEFT_MOUSE_UP, NSEventType::RIGHT_MOUSE_UP, NSEventType::OTHER_MOUSE_UP => $this->released($event, $point),
            NSEventType::SCROLL_WHEEL => $this->wheeled($window, $event, $point),
            default => null,
        };
    }

    /**
     * The press ended without a release: the release goes out at the last point posted, with the
     * modifiers last seen; nothing when no press is owned.
     */
    public function interrupt(): void
    {
        $press = $this->holding();
        $this->press = null;
        if (is_null($press)) {
            return;
        }
        [$canvas, , $button, $x, $y, $modifiers] = $press;
        $this->session->post(new CanvasReleased($this->windowName(), $canvas->path(), $canvas->uuid(), $x, $y, $button, $modifiers));
    }

    /** A mouse event's button: left and right by type (a built event's button number reads 0), others by number: 2 middle, 3 and 4 the side buttons. */
    public static function button(NSEvent $event): ?MouseButton
    {
        return match ($event->type()) {
            NSEventType::LEFT_MOUSE_DOWN, NSEventType::LEFT_MOUSE_DRAGGED, NSEventType::LEFT_MOUSE_UP => MouseButton::LEFT,
            NSEventType::RIGHT_MOUSE_DOWN, NSEventType::RIGHT_MOUSE_DRAGGED, NSEventType::RIGHT_MOUSE_UP => MouseButton::RIGHT,
            default => match ($event->buttonNumber()) {
                2 => MouseButton::MIDDLE,
                3 => MouseButton::X1,
                4 => MouseButton::X2,
                default => null,
            },
        };
    }

    private function pressed(AppkitWindow $window, NSEvent $event, NSPoint $point): void
    {
        // A press AppkitRightClicks withheld opened a context menu, which has already taken its release.
        $menu = $this->session->withheld() === $event;
        if (! is_null($this->holding())) {
            if ($menu) {
                $this->interrupt();
            }

            return;
        }
        $button = self::button($event);
        if (is_null($button)) {
            return;
        }
        [$canvas, $view] = AppkitCanvasClicks::canvasAt($window, $point);
        if (is_null($canvas) || ! $canvas->isWatchingPointer()) {
            return;
        }
        [$x, $y] = AppkitRightClicks::topLeft($view, $point);
        $modifiers = AppkitInputEngine::modifiers($event->modifierFlags());
        $this->press = [$canvas, $view, $button, $x, $y, $modifiers];
        $this->session->post(new CanvasPressed($window->name(), $canvas->path(), $canvas->uuid(), $x, $y, $button, $event->clickCount(), $modifiers));
        if ($menu) {
            $this->interrupt();
        }
    }

    private function dragged(NSEvent $event, NSPoint $point): void
    {
        $press = $this->holding();
        if (is_null($press)) {
            return;
        }
        [$canvas, $view, $button, $lx, $ly] = $press;
        [$x, $y] = AppkitRightClicks::topLeft($view, $point);
        if ($x === $lx && $y === $ly) {
            return;
        }
        $modifiers = AppkitInputEngine::modifiers($event->modifierFlags());
        $this->press = [$canvas, $view, $button, $x, $y, $modifiers];
        $this->session->post(new CanvasDragged($this->windowName(), $canvas->path(), $canvas->uuid(), $x, $y, $button, $modifiers));
    }

    private function released(NSEvent $event, NSPoint $point): void
    {
        $press = $this->holding();
        if (is_null($press) || self::button($event) !== $press[2]) {
            return;
        }
        [$canvas, $view, $button] = $press;
        $this->press = null;
        [$x, $y] = AppkitRightClicks::topLeft($view, $point);
        $this->session->post(new CanvasReleased($this->windowName(), $canvas->path(), $canvas->uuid(), $x, $y, $button, AppkitInputEngine::modifiers($event->modifierFlags())));
    }

    private function wheeled(AppkitWindow $window, NSEvent $event, NSPoint $point): void
    {
        [$canvas] = AppkitCanvasClicks::canvasAt($window, $point);
        if (is_null($canvas) || ! $canvas->isWatchingPointer()) {
            return;
        }
        $this->session->post(new CanvasWheeled(
            $window->name(), $canvas->path(), $canvas->uuid(),
            -$event->scrollingDeltaX(), -$event->scrollingDeltaY(), $event->hasPreciseScrollingDeltas(),
            AppkitInputEngine::modifiers($event->modifierFlags()),
        ));
        $this->session->withhold($event);
    }

    /**
     * The press owned, if its canvas still takes pointer mail. A canvas that was removed, stopped
     * watching, or was disabled itself or inside a group drops the press here, silently.
     *
     * @return array{TKCanvas, NSView, MouseButton, float, float, Modifiers}|null
     */
    private function holding(): ?array
    {
        $press = $this->press;
        if (is_null($press)) {
            return null;
        }
        $canvas = $press[0];
        if ($canvas->isRemoved() || ! $canvas->isWatchingPointer()) {
            $this->press = null;

            return null;
        }
        for ($at = $canvas; ! is_null($at); $at = $at->parent()) {
            if (! $at->isEnabled()) {
                $this->press = null;

                return null;
            }
        }

        return $press;
    }

    private function windowName(): string
    {
        return $this->window->get()?->name() ?? '';
    }
}
