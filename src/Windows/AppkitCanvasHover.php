<?php

namespace Jovian\Toolkits\Appkit\Windows;

use Jovian\Toolkits\Appkit\Bridge\AppkitSession;
use Jovian\Toolkits\Appkit\Input\AppkitInputEngine;
use Jovian\Toolkits\Appkit\Primitives\AppkitCanvas;
use NSEvent;
use NSEventType;
use NSPoint;
use Surface\Contracts\HumanInput\InputTap;
use Surface\Contracts\Windows\Mail\View\CanvasEntered;
use Surface\Contracts\Windows\Mail\View\CanvasLeft;
use Surface\Contracts\Windows\Mail\View\CanvasMoved;
use Surface\Contracts\Windows\Primitives\TKCanvas;
use WeakReference;

/**
 * Hover over one window's watching canvases, a session tap after AppkitCanvasPointer. Each canvas
 * view carries a tracking area (AppkitCanvas), so AppKit sends its enters, exits and moves: an
 * enter or exit names its canvas by the event's tracking area, a move by the canvas under it, and a
 * move over another canvas, or none, is the last one's exit too. Moves post CanvasMoved
 * (latest-wins). While the pointer tap owns a press nothing posts; a release away from the canvas
 * the pointer was over posts CanvasLeft, after the pointer tap's CanvasReleased.
 */
final class AppkitCanvasHover implements InputTap
{
    private bool $watching = true;

    private readonly WeakReference $window;

    /** @var WeakReference<TKCanvas>|null the canvas the pointer is over */
    private ?WeakReference $inside = null;

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

    public function detach(): void
    {
        $this->session->untap($this);
        $this->watching = false;
        $this->inside = null;
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

    public function seen(NSEvent $event, NSPoint $point): void
    {
        $window = $this->window->get();
        if (is_null($window) || ! $this->watching || $window->canvasPointer()->pressing()) {
            return;
        }
        match ($event->type()) {
            NSEventType::MOUSE_MOVED => $this->moved($window, $event, $point),
            NSEventType::MOUSE_ENTERED => $this->entered($window, $this->canvasOf($window, $event)),
            NSEventType::MOUSE_EXITED => $this->left($window, $this->canvasOf($window, $event)),
            NSEventType::LEFT_MOUSE_UP, NSEventType::RIGHT_MOUSE_UP, NSEventType::OTHER_MOUSE_UP => $this->released($window, $point),
            default => null,
        };
    }

    public function interrupt(): void
    {
        $this->inside = null;
    }

    private function moved(AppkitWindow $window, NSEvent $event, NSPoint $point): void
    {
        [$canvas, $view] = AppkitCanvasClicks::canvasAt($window, $point);
        $canvas = $canvas?->isWatchingPointer() ? $canvas : null;
        $inside = $this->inside?->get();
        if ($inside !== $canvas) {
            $this->left($window, $inside);
            $this->entered($window, $canvas);
        }
        if (is_null($canvas)) {
            return;
        }
        [$x, $y] = AppkitRightClicks::topLeft($view, $point);
        $this->session->postLatest(
            "view.moved.{$window->name()}.{$canvas->path()}",
            new CanvasMoved($window->name(), $canvas->path(), $canvas->uuid(), $x, $y, AppkitInputEngine::modifiers($event->modifierFlags())),
        );
    }

    private function released(AppkitWindow $window, NSPoint $point): void
    {
        $inside = $this->inside?->get();
        if (! is_null($inside) && AppkitCanvasClicks::canvasAt($window, $point)[0] !== $inside) {
            $this->left($window, $inside);
        }
    }

    private function entered(AppkitWindow $window, ?TKCanvas $canvas): void
    {
        if (is_null($canvas) || $this->inside?->get() === $canvas || $canvas->isRemoved() || ! $canvas->isWatchingPointer()) {
            return;
        }
        for ($at = $canvas; ! is_null($at); $at = $at->parent()) {
            if (! $at->isEnabled()) {
                return;
            }
        }
        $this->inside = WeakReference::create($canvas);
        $this->session->post(new CanvasEntered($window->name(), $canvas->path(), $canvas->uuid()));
    }

    private function left(AppkitWindow $window, ?TKCanvas $canvas): void
    {
        if (is_null($canvas) || $this->inside?->get() !== $canvas) {
            return;
        }
        $this->inside = null;
        if (! $canvas->isRemoved()) {
            // The canvas's last CanvasMoved waits among the latest-wins mail: it goes out before the exit.
            $this->session->flushLatest();
            $this->session->post(new CanvasLeft($window->name(), $canvas->path(), $canvas->uuid()));
        }
    }

    /** The watching canvas an enter or exit's tracking area belongs to. */
    private function canvasOf(AppkitWindow $window, NSEvent $event): ?TKCanvas
    {
        $area = $event->trackingArea();
        foreach ($window->registry()->all() as $primitive) {
            if ($primitive instanceof AppkitCanvas && $primitive->trackingArea() === $area) {
                return $primitive;
            }
        }

        return null;
    }
}
