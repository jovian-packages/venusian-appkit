<?php

namespace Jovian\Toolkits\Appkit\Primitives;

use Jovian\Toolkits\Appkit\Primitives\Concerns\AppkitPrimitive;
use NSControlSize;
use NSEvent;
use NSEventMask;
use NSEventType;
use NSLayoutConstraint;
use NSObject;
use NSPoint;
use NSRect;
use NSScroller;
use NSScrollerPart;
use NSScrollerStyle;
use Surface\Contracts\Windows\Mail\View\ScrollChanged;
use Surface\Contracts\Windows\Primitives\Placement;
use Surface\Contracts\Windows\ToolkitWindow;
use Surface\Windows\Primitives\TKPrimitiveGroup;
use Surface\Windows\Primitives\TKScrollBar;
use ObjCTarget;
use WeakReference;

/**
 * A standalone NSScroller: doubleValue is value / (maximum − page), the knob page / maximum of the
 * track. Always the legacy style, as thick as a legacy regular scroller: an overlay scroller's knob
 * shows only while an NSScrollView flashes it, so a standalone one draws its track alone; GTK and
 * Qt draw their standalone bars always too. The target hears the user: a click in the knob slot
 * reads doubleValue, a page part moves one page, each posting ScrollChanged (latest-wins). AppKit
 * tracks a knob drag in a loop of its own, and the app's loop waits until the release, so a local
 * monitor takes a press on the knob and the driver tracks the drag itself: each drag event moves
 * the value as the app's loop hands it out. AppKit passes a wheel over a scroller on, so
 * AppkitCanvasPointer hands it to wheel().
 */
class AppkitScrollBar extends TKScrollBar
{
    use AppkitPrimitive;

    /** A precise wheel's points per line, NSScrollView's line. */
    private const float LINE_POINTS = 10.0;

    /** Controls hold targets weakly: the primitive keeps it. */
    protected ObjCTarget $target;

    protected NSLayoutConstraint $thickness;

    /** A precise wheel's points not yet a whole line. */
    private float $wheel_rest = 0.0;

    private ?NSObject $monitor = null;

    /** While the driver tracks a knob drag: where in the knob it was pressed, along the bar. */
    private ?float $grab = null;

    public function __construct(string $name, ToolkitWindow $window, ?TKPrimitiveGroup $parent, Placement $placement, bool $vertical)
    {
        parent::__construct($name, $window, $parent, $placement, $vertical);
        $style = NSScrollerStyle::LEGACY;
        $width = NSScroller::scrollerWidthForControlSizeScrollerStyle(NSControlSize::REGULAR, $style);
        $scroller = $this->adoptNative(NSScroller::initWithFrame($vertical ? new NSRect(0.0, 0.0, $width, 100.0) : new NSRect(0.0, 0.0, 100.0, $width)));
        $scroller->setScrollerStyle($style);
        $scroller->setEnabled(true);
        $this->thickness = ($vertical ? $scroller->widthAnchor() : $scroller->heightAnchor())->constraintEqualToConstant($width);
        NSLayoutConstraint::activateConstraints([$this->thickness]);
        $this->applyRange(0, 0, 0);

        // The target holds its callable where PHP's collector cannot see it: a weak reference keeps it from holding the primitive.
        $self = WeakReference::create($this);
        $this->target = new ObjCTarget(static fn () => $self->get()?->moved());
        $scroller->setTarget($this->target);
        $scroller->setAction(ObjCTarget::ACTION);
        $this->monitor = NSEvent::addLocalMonitorForEventsMatchingMaskHandler(
            NSEventMask::LEFT_MOUSE_DOWN->value | NSEventMask::LEFT_MOUSE_DRAGGED->value | NSEventMask::LEFT_MOUSE_UP->value,
            static fn (NSEvent $event): ?NSEvent => is_null($bar = $self->get()) ? $event : $bar->knobMouse($event),
        );
    }

    public function native(): NSScroller
    {
        return $this->native;
    }

    /**
     * A wheel over the bar: step per line, a precise wheel's points one line per 10, the remainder
     * kept for the next. Posts ScrollChanged when the value moved.
     */
    public function wheel(NSEvent $event): void
    {
        $delta = -($this->vertical ? $event->scrollingDeltaY() : ($event->scrollingDeltaX() ?: $event->scrollingDeltaY()));
        if ($event->hasPreciseScrollingDeltas()) {
            $points = $this->wheel_rest + $delta;
            $lines = (int) ($points / self::LINE_POINTS);
            $this->wheel_rest = $points - $lines * self::LINE_POINTS;
        } else {
            $lines = (int) round($delta);
        }
        $this->userMove($this->value + $lines * $this->step);
    }

    protected function applyRange(int $maximum, int $page, int $value): void
    {
        $this->native->setKnobProportion($maximum > 0 ? min(1.0, $page / $maximum) : 1.0);
        $this->applyValue($value);
    }

    protected function applyStep(int $line): void {}

    protected function applyValue(int $value): void
    {
        $span = $this->maximum - $this->page;
        $this->native->setDoubleValue($span > 0 ? $value / $span : 0.0);
    }

    protected function releaseNative(): void
    {
        if (! is_null($this->monitor)) {
            NSEvent::removeMonitor($this->monitor);
            $this->monitor = null;
        }
        $this->native->setTarget(null);
    }

    protected function moved(): void
    {
        $part = $this->native->hitPart();
        $part = $part instanceof NSScrollerPart ? $part : NSScrollerPart::tryFrom($part);
        switch ($part) {
            case NSScrollerPart::KNOB:
            case NSScrollerPart::KNOB_SLOT:
                $this->nativeMoved((int) round($this->native->doubleValue() * max(0, $this->maximum - $this->page)));
                $this->postScroll();

                return;
            case NSScrollerPart::DECREMENT_PAGE:
                $this->userMove($this->value - $this->page);

                return;
            case NSScrollerPart::INCREMENT_PAGE:
                $this->userMove($this->value + $this->page);

                return;
            default:
                return;
        }
    }

    /**
     * A left-button event, before AppKit has it: null when the driver took it. A press takes only
     * the knob of this bar where it is the view under the press and enabled; the drag and the
     * release after it are the driver's.
     */
    protected function knobMouse(NSEvent $event): ?NSEvent
    {
        $type = $event->type();
        if ($type === NSEventType::LEFT_MOUSE_DOWN) {
            // A drag whose release went to another app (⌘-Tab mid-drag) ends at the next press.
            $this->grab = null;
            $window = $this->native->window();
            if ($this->removed || is_null($window) || $event->window() !== $window || ! $this->native->isEnabled()
                || $window->contentView()?->hitTest($event->locationInWindow()) !== $this->native
                || $this->native->testPart($event->locationInWindow()) !== NSScrollerPart::KNOB) {
                return $event;
            }
            $knob = $this->native->rectForPart(NSScrollerPart::KNOB);
            $this->grab = $this->along($this->local($event)) - ($this->vertical ? $knob->y : $knob->x);

            return null;
        }
        if (is_null($this->grab)) {
            return $event;
        }
        $this->dragTo($this->local($event));
        if ($type === NSEventType::LEFT_MOUSE_UP) {
            $this->grab = null;
        }

        return null;
    }

    /** Put the knob's grabbed point at $point, the knob kept in its slot. */
    private function dragTo(NSPoint $point): void
    {
        $slot = $this->native->rectForPart(NSScrollerPart::KNOB_SLOT);
        $knob = $this->native->rectForPart(NSScrollerPart::KNOB);
        $travel = $this->vertical ? $slot->height - $knob->height : $slot->width - $knob->width;
        $fraction = $travel > 0.0 ? max(0.0, min(1.0, ($this->along($point) - $this->grab - ($this->vertical ? $slot->y : $slot->x)) / $travel)) : 0.0;
        $this->userMove((int) round($fraction * max(0, $this->maximum - $this->page)));
    }

    private function local(NSEvent $event): NSPoint
    {
        return $this->native->convertPointFromView($event->locationInWindow(), null);
    }

    private function along(NSPoint $point): float
    {
        return $this->vertical ? $point->y : $point->x;
    }

    /** The user's move to $value: clamped, written, posted when it changed. */
    private function userMove(int $value): void
    {
        $was = $this->value;
        $this->nativeMoved($value);
        $this->applyValue($this->value);
        if ($this->value !== $was) {
            $this->postScroll();
        }
    }

    private function postScroll(): void
    {
        $this->session()->postLatest(
            "view.scroll-changed.{$this->window->name()}.{$this->path()}",
            new ScrollChanged($this->window->name(), $this->path(), $this->uuid, $this->value),
        );
    }
}
