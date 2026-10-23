<?php

namespace Jovian\Toolkits\Appkit\Windows;

use Jovian\Toolkits\Appkit\Bridge\AppkitSession;
use NSEvent;
use NSEventModifierFlags;
use NSEventType;
use NSPoint;
use NSView;
use Surface\Contracts\HumanInput\InputTap;
use Surface\Contracts\Windows\Mail\View\CanvasClicked;
use Surface\Contracts\Windows\Primitives\TKCanvas;
use WeakReference;

/**
 * A toolkit window's canvas clicks as mail, from the events the session pumps (a tap on the session):
 * a primary press on a canvas without Control (Ctrl-click is a right click) and its release over the
 * same canvas, the pointer not dragged more than AppKit's drag distance in between. The mail carries
 * where the press landed, from the canvas's top-left whatever its view's flipping.
 */
final class AppkitCanvasClicks implements InputTap
{
    /** How far a press may drag and still click, in points: NSEvent's default drag threshold. */
    public const float DRAG_DISTANCE = 3.0;

    private bool $watching = true;

    /** @var WeakReference<AppkitWindow> the window holds this; a strong reference back would keep a closed window alive */
    private readonly WeakReference $window;

    /** @var array{TKCanvas, NSPoint, float, float}|null the press: its canvas, its window point, its canvas point */
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
        $point = $native_event->locationInWindow();
        match ($native_event->type()) {
            NSEventType::LEFT_MOUSE_DOWN => $this->pressed($window, $point, ($native_event->modifierFlags() & NSEventModifierFlags::CONTROL->value) !== 0),
            NSEventType::LEFT_MOUSE_DRAGGED => $this->dragged($point),
            NSEventType::LEFT_MOUSE_UP => $this->released($window, $point),
            default => null,
        };
    }

    private function pressed(AppkitWindow $window, NSPoint $point, bool $control): void
    {
        $this->press = null;
        if ($control) {
            return;
        }
        [$canvas, $view] = self::canvasAt($window, $point);
        if (! is_null($canvas)) {
            [$x, $y] = AppkitRightClicks::topLeft($view, $point);
            $this->press = [$canvas, $point, $x, $y];
        }
    }

    private function dragged(NSPoint $point): void
    {
        if (! is_null($this->press) && hypot($point->x - $this->press[1]->x, $point->y - $this->press[1]->y) > self::DRAG_DISTANCE) {
            $this->press = null;
        }
    }

    private function released(AppkitWindow $window, NSPoint $point): void
    {
        $press = $this->press;
        $this->press = null;
        if (is_null($press)) {
            return;
        }
        [$canvas] = self::canvasAt($window, $point);
        if ($canvas === $press[0]) {
            $this->session->post(new CanvasClicked($window->name(), $canvas->path(), $canvas->uuid(), $press[2], $press[3]));
        }
    }

    /**
     * The enabled canvas under a window point, and its view: hitTest: walked up superviews to the first
     * a canvas owns (a lent surface's view sits inside it). Null when it or one of its groups is
     * disabled, or no canvas is there. Shared with AppkitCanvasPointer, which routes a press the same way.
     *
     * @return array{TKCanvas|null, NSView|null}
     */
    public static function canvasAt(AppkitWindow $window, NSPoint $point): array
    {
        $content = $window->native()->contentView();
        if (is_null($content)) {
            return [null, null];
        }
        $owners = [];
        foreach ($window->registry()->all() as $primitive) {
            if ($primitive instanceof TKCanvas) {
                foreach (['native', 'placedView'] as $view) {
                    if (method_exists($primitive, $view)) {
                        $owners[spl_object_id($primitive->{$view}())] = $primitive;
                    }
                }
            }
        }
        $frame = $content->superview();
        for ($view = $content->hitTest(is_null($frame) ? $point : $frame->convertPointFromView($point, null)); ! is_null($view); $view = $view->superview()) {
            $canvas = $owners[spl_object_id($view)] ?? null;
            if (is_null($canvas)) {
                continue;
            }
            for ($at = $canvas; ! is_null($at); $at = $at->parent()) {
                if (! $at->isEnabled()) {
                    return [null, null];
                }
            }

            return [$canvas, $view];
        }

        return [null, null];
    }
}
