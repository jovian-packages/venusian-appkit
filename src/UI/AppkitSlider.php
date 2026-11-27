<?php

namespace Jovian\Toolkits\Appkit\UI;

use NSEvent;
use NSObject;
use NSPoint;
use NSSlider;
use NSEventMask;
use NSEventType;
use ObjCTarget;
use WeakReference;
use NSApplication;
use NSLayoutConstraint;
use Surface\Toolkits\UI\TKSlider;
use Jovian\Toolkits\Appkit\UI\Concerns\AppkitNative;
use Jovian\Toolkits\Appkit\UI\Concerns\TakesInputFromAbove;

/**
 * A continuous NSSlider. It has no natural width, so it asks for one just above the stack's pull
 * to zero.
 *
 * AppKit tracks a press on a slider in a loop of its own, inside sendEvent:, until the button
 * comes up, so the session's pump takes nothing until then. The slider takes the press itself
 * instead, from a local event monitor, and has its cell track it one event at a time: each drag
 * comes back to the pump and its value is posted from there, as on the other toolkits. A slider
 * accepts the first click: a press in a window that is not key makes it key, as AppKit does.
 */
class AppkitSlider extends TKSlider
{
    use AppkitNative {
        releaseNative as private releaseView;
    }
    use TakesInputFromAbove;

    /** Points across when nothing stretches it. */
    private const float WIDTH = 120.0;

    /** A press, its drag and its release. */
    private const int EVENTS = NSEventMask::LEFT_MOUSE_DOWN->value | NSEventMask::LEFT_MOUSE_DRAGGED->value | NSEventMask::LEFT_MOUSE_UP->value;

    /** Controls hold their targets weakly: the primitive keeps it. */
    private ?ObjCTarget $target = null;

    /** The monitor that takes a press on the slider and its drag. */
    private ?NSObject $monitor = null;

    /** Where the pointer was at the last step, in the slider's coordinates, while tracking. */
    private ?NSPoint $tracking = null;

    protected function bootstrapNative(): void
    {
        $self = WeakReference::create($this);
        $this->monitor = NSEvent::addLocalMonitorForEventsMatchingMaskHandler(
            self::EVENTS,
            static fn (NSEvent $event): ?NSEvent => is_null($slider = $self->get()) ? $event : $slider->mouse($event),
        );
        $this->target = new ObjCTarget(static function () use ($self): void {
            $slider = $self->get();
            $slider?->slid($slider->sliderNative()->doubleValue());
        });

        $slider = NSSlider::sliderWithValueMinValueMaxValueTargetAction($this->value(), $this->min(), $this->max(), $this->target, ObjCTarget::ACTION);
        $slider->setContinuous(true);
        $this->adoptNative($slider);

        $width = $slider->widthAnchor()->constraintGreaterThanOrEqualToConstant(self::WIDTH);
        $width->setPriority(490.0);
        NSLayoutConstraint::activateConstraints([$width]);
    }

    protected function applyValue(float $value): void
    {
        $this->sliderNative()->setDoubleValue($value);
    }

    protected function applyRange(float $min, float $max, float $value): void
    {
        $slider = $this->sliderNative();
        $slider->setMinValue($min);
        $slider->setMaxValue($max);
        $slider->setDoubleValue($value);
    }

    protected function applyNativeEnabled(bool $takes_input): void
    {
        $this->sliderNative()->setEnabled($takes_input);
    }

    /**
     * A mouse event the app is about to dispatch: the slider's own while it tracks, and a press
     * on the slider where it is the view the press hits.
     *
     * @internal
     * @return NSEvent|null the event, or null when the slider took it
     */
    public function mouse(NSEvent $event): ?NSEvent
    {
        $slider = $this->sliderNative();

        if (! is_null($this->tracking)) {
            $at = $slider->convertPointFromView($event->locationInWindow(), null);
            $cell = $slider->cell();
            if ($event->type() === NSEventType::LEFT_MOUSE_UP) {
                $cell->stopTrackingAtInViewMouseIsUp($this->tracking, $at, $slider, true);
                $this->tracking = null;
            } elseif ($event->type() === NSEventType::LEFT_MOUSE_DRAGGED) {
                if (! $cell->continueTrackingAtInView($this->tracking, $at, $slider)) {
                    $cell->stopTrackingAtInViewMouseIsUp($this->tracking, $at, $slider, false);
                    $this->tracking = null;
                } else {
                    $this->tracking = $at;
                }
            }
            $this->slid($slider->doubleValue());

            return null;
        }

        if ($event->type() !== NSEventType::LEFT_MOUSE_DOWN || ! $slider->isEnabled() || ! $this->pressedHere($event)) {
            return $event;
        }

        $window = $slider->window();
        if (! $window->isKeyWindow()) {
            NSApplication::sharedApplication()->activateIgnoringOtherApps(true);
            $window->makeKeyAndOrderFront(null);
        }
        $at = $slider->convertPointFromView($event->locationInWindow(), null);
        if (! $slider->cell()->startTrackingAtInView($at, $slider)) {
            return $event;
        }
        $this->tracking = $at;
        $this->slid($slider->doubleValue());

        return null;
    }

    /** Whether $event is a press in the slider's window that lands on the slider and nothing over it. */
    private function pressedHere(NSEvent $event): bool
    {
        $slider = $this->sliderNative();
        $window = $slider->window();
        if (is_null($window) || $event->windowNumber() !== $window->windowNumber()) {
            return false;
        }

        // The content view's hitTest: takes a point in its superview's space: the window's own.
        return $window->contentView()?->hitTest($event->locationInWindow())?->pointer() === $slider->pointer();
    }

    protected function releaseNative(): void
    {
        if (! is_null($this->monitor)) {
            NSEvent::removeMonitor($this->monitor);
            $this->monitor = null;
        }
        $this->tracking = null;
        $this->sliderNative()->setTarget(null);
        $this->target = null;
        $this->releaseView();
    }

    private function sliderNative(): NSSlider
    {
        /** @var NSSlider */
        return $this->native();
    }
}
