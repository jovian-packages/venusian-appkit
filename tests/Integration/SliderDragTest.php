<?php

declare(strict_types=1);

use Surface\Contracts\Toolkits\Mail\Views\ValueChanged;

/**
 * A window with a slider from 0 to 10 across the top, laid out. The press and drag events are put
 * in the app's own queue with postEvent:atStart: and taken by the pump: nothing goes to the system.
 *
 * Should AppKit's own tracking loop take a press, it would wait for a release the test posts only
 * later. A timer that fires only in the loop's mode posts that release, so such a test fails
 * instead of waiting.
 */
function dragSlider(): array
{
    $window = openWindow('slider', 400, 300);
    $slider = $window->column('root')->slider('zoom', 0.0, 10.0, 0.0)->fill(true, false);
    pumpFor(0.1);
    takeMail();

    $watchdog = new ObjCTarget(static fn () => postMouse($window, NSEventType::LEFT_MOUSE_UP, new NSPoint(0.0, 0.0)));
    $timer = NSTimer::timerWithTimeIntervalTargetSelectorUserInfoRepeats(1.0, $watchdog, ObjCTarget::ACTION, null, true);
    NSRunLoop::mainRunLoop()->addTimerForMode($timer, NSEventTrackingRunLoopMode);
    test()->watchdog = [$watchdog, $timer];

    return [$window, $slider];
}

afterEach(function (): void {
    if (isset($this->watchdog)) {
        $this->watchdog[1]->invalidate();
    }
});

/** A point $x points in from the slider's leading edge, at its middle, in the window's space. */
function onSlider(object $slider, float $x): NSPoint
{
    $frame = $slider->native()->convertRectToView($slider->native()->bounds(), null);

    return new NSPoint($frame->x + $x, $frame->y + $frame->height / 2);
}

function postMouse(object $window, NSEventType $type, NSPoint $at): void
{
    NSApplication::sharedApplication()->postEventAtStart(
        NSEvent::mouseEventWithTypeLocationModifierFlagsTimestampWindowNumberContextEventNumberClickCountPressure(
            $type, $at, 0, 0.0, $window->native()->windowNumber(), null, 0, 1, 1.0,
        ),
        false,
    );
}

/**
 * Pump until the queue's events are taken, then the values the slider posted.
 *
 * @return list<float>
 */
function slidValues(): array
{
    pumpFor(0.15);

    return array_values(array_map(
        static fn (ValueChanged $m): float => $m->value,
        array_filter(takeMail(), static fn (object $m): bool => $m instanceof ValueChanged),
    ));
}

it('posts a value for each step of a drag, before the button comes up', function (): void {
    [$window, $slider] = dragSlider();

    postMouse($window, NSEventType::LEFT_MOUSE_DOWN, onSlider($slider, 60.0));
    $pressed = slidValues();
    postMouse($window, NSEventType::LEFT_MOUSE_DRAGGED, onSlider($slider, 120.0));
    $first = slidValues();
    postMouse($window, NSEventType::LEFT_MOUSE_DRAGGED, onSlider($slider, 240.0));
    $second = slidValues();
    postMouse($window, NSEventType::LEFT_MOUSE_UP, onSlider($slider, 240.0));
    $released = slidValues();

    expect($pressed)->toHaveCount(1)
        ->and($first)->toHaveCount(1)
        ->and($second)->toHaveCount(1)
        ->and($first[0])->toBeGreaterThan($pressed[0])
        ->and($second[0])->toBeGreaterThan($first[0])
        ->and($released)->toBe([])
        ->and($slider->value())->toBe($second[0])
        ->and($slider->native()->doubleValue())->toBe($second[0]);
    $window->close();
});

it('stops tracking when the button comes up', function (): void {
    [$window, $slider] = dragSlider();

    postMouse($window, NSEventType::LEFT_MOUSE_DOWN, onSlider($slider, 60.0));
    postMouse($window, NSEventType::LEFT_MOUSE_DRAGGED, onSlider($slider, 120.0));
    postMouse($window, NSEventType::LEFT_MOUSE_UP, onSlider($slider, 120.0));
    slidValues();
    $held = $slider->value();
    postMouse($window, NSEventType::LEFT_MOUSE_DRAGGED, onSlider($slider, 300.0));

    expect(slidValues())->toBe([])
        ->and($slider->value())->toBe($held);
    $window->close();
});

it('leaves a press elsewhere, and a press on a disabled slider, to AppKit', function (): void {
    [$window, $slider] = dragSlider();
    $below = onSlider($slider, 120.0);

    postMouse($window, NSEventType::LEFT_MOUSE_DOWN, new NSPoint($below->x, $below->y - 100.0));
    postMouse($window, NSEventType::LEFT_MOUSE_UP, new NSPoint($below->x, $below->y - 100.0));
    $elsewhere = slidValues();
    $slider->setEnabled(false);
    pumpFor(0.05);
    postMouse($window, NSEventType::LEFT_MOUSE_DOWN, $below);
    postMouse($window, NSEventType::LEFT_MOUSE_UP, $below);

    expect($elsewhere)->toBe([])
        ->and(slidValues())->toBe([])
        ->and($slider->value())->toBe(0.0);
    $window->close();
});
