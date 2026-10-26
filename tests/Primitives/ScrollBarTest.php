<?php

declare(strict_types=1);

use Jovian\Toolkits\Appkit\Primitives\AppkitScrollBar;
use Jovian\Toolkits\Appkit\Windows\AppkitWindow;
use Surface\Contracts\Windows\Mail\View\ScrollChanged;

beforeEach(function (): void {
    driver()->closeAll();
    pumpFor(0.05);
    takeMail(session());
});

afterEach(function (): void {
    driver()->closeAll();
    pumpFor(0.05);
    takeMail(session());
});

/** A shown window with a vertical bar 'bar' in column 'm', 240 points tall. */
function scrollBarWindow(): array
{
    $window = driver()->open('main', 200, 300);
    $bar = $window->column('m')->scrollBar('bar')->minSize(0, 240);
    $bar->setRange(1000, 100);
    canvasWindowPoint($window, $bar->native(), 0.0, 0.0);
    pumpFor(0.1);
    takeMail(session());

    return [$window, $bar];
}

/** Mouse events at window points, queued in order and pumped through the session as the user's. */
function queueMouse(AppkitWindow $window, array $events): void
{
    $app = NSApplication::sharedApplication();
    foreach ($events as [$type, $at]) {
        $app->postEventAtStart(mouseAt($window, $at, $type), false);
    }
    pumpFor(0.3);
}

/** The scroll mail posted since the last read, latest-wins mail flushed first. */
function scrollMail(): array
{
    session()->flushLatest();

    return array_values(array_filter(takeMail(session()), fn (object $mail): bool => $mail instanceof ScrollChanged));
}

it('is a scroller whose value and knob read the range', function (): void {
    [$window, $bar] = scrollBarWindow();

    $bar->setValue(450);

    expect($bar)->toBeInstanceOf(AppkitScrollBar::class)
        ->and($bar->native()->knobProportion())->toEqualWithDelta(0.1, 0.0001)
        ->and($bar->native()->doubleValue())->toEqualWithDelta(0.5, 0.0001)
        ->and($bar->native()->frame()->width)->toEqual(NSScroller::scrollerWidthForControlSizeScrollerStyle(NSControlSize::REGULAR, NSScrollerStyle::LEGACY))
        ->and(scrollMail())->toBe([]);

    $bar->setRange(0, 0);
    expect($bar->native()->knobProportion())->toBe(1.0)
        ->and($bar->native()->doubleValue())->toBe(0.0);
    $window->close();
});

it('draws as a legacy scroller whatever the system style: a standalone overlay knob never shows', function (): void {
    [$window, $bar] = scrollBarWindow();

    expect($bar->native()->scrollerStyle())->toBe(NSScrollerStyle::LEGACY);
    // The system's style changing leaves it legacy.
    NSNotificationCenter::defaultCenter()->postNotificationNameObject(NSPreferredScrollerStyleDidChangeNotification, null);
    pumpFor(0.1);
    expect($bar->native()->scrollerStyle())->toBe(NSScrollerStyle::LEGACY);
    $window->close();
});

it('pulls the native value in when the range shrinks under it, and posts nothing', function (): void {
    [$window, $bar] = scrollBarWindow();
    $bar->setValue(900);

    $bar->setRange(500, 100);

    expect($bar->value())->toBe(400)
        ->and($bar->native()->doubleValue())->toEqualWithDelta(1.0, 0.0001)
        ->and(scrollMail())->toBe([]);
    $window->close();
});

it('moves one page toward a click in the track', function (): void {
    [$window, $bar] = scrollBarWindow();
    $below = canvasWindowPoint($window, $bar->native(), $bar->native()->frame()->width / 2, 230.0);

    queueMouse($window, [[NSEventType::LEFT_MOUSE_DOWN, $below], [NSEventType::LEFT_MOUSE_UP, $below]]);

    expect(scrollMail())->toEqual([new ScrollChanged('main', 'm.bar', $bar->uuid(), 100)])
        ->and($bar->value())->toBe(100)
        ->and($bar->native()->doubleValue())->toEqualWithDelta(100 / 900, 0.0001);
    $window->close();
});

it('posts the dragged value while the knob moves', function (): void {
    [$window, $bar] = scrollBarWindow();
    $middle = $bar->native()->frame()->width / 2;
    $knob = canvasWindowPoint($window, $bar->native(), $middle, 10.0);
    $down = canvasWindowPoint($window, $bar->native(), $middle, 110.0);

    queueMouse($window, [
        [NSEventType::LEFT_MOUSE_DOWN, $knob],
        [NSEventType::LEFT_MOUSE_DRAGGED, $down],
        [NSEventType::LEFT_MOUSE_UP, $down],
    ]);
    $mail = scrollMail();

    expect($mail)->not->toBe([])
        ->and(end($mail)->value)->toBe($bar->value())
        ->and($bar->value())->toBeGreaterThan(300)
        ->and($bar->value())->toBe((int) round($bar->native()->doubleValue() * 900));
    $window->close();
});

it('ends a knob drag whose release went to another app at the next press', function (): void {
    [$window, $bar] = scrollBarWindow();
    $middle = $bar->native()->frame()->width / 2;
    $knob = canvasWindowPoint($window, $bar->native(), $middle, 10.0);
    $down = canvasWindowPoint($window, $bar->native(), $middle, 110.0);
    queueMouse($window, [
        [NSEventType::LEFT_MOUSE_DOWN, $knob],
        [NSEventType::LEFT_MOUSE_DRAGGED, $down],
    ]);
    $value = $bar->value();
    scrollMail();

    $beside = canvasWindowPoint($window, $bar->native(), $middle + 60.0, 200.0);
    $further = canvasWindowPoint($window, $bar->native(), $middle + 60.0, 230.0);
    queueMouse($window, [
        [NSEventType::LEFT_MOUSE_DOWN, $beside],
        [NSEventType::LEFT_MOUSE_DRAGGED, $further],
        [NSEventType::LEFT_MOUSE_UP, $further],
    ]);

    expect($value)->toBeGreaterThan(300)
        ->and($bar->value())->toBe($value)
        ->and(scrollMail())->toBe([]);
    $window->close();
});

it('lets the app run while the knob is dragged: a drag step moves the value before the release is read', function (): void {
    [$window, $bar] = scrollBarWindow();
    $app = NSApplication::sharedApplication();
    $middle = $bar->native()->frame()->width / 2;
    $knob = canvasWindowPoint($window, $bar->native(), $middle, 10.0);
    $down = canvasWindowPoint($window, $bar->native(), $middle, 110.0);
    // A marker between the drag and the release: when the app's own loop hands it out, the value
    // must have moved and the release still be queued. AppKit's knob tracking would take the
    // drag and the release in its own loop first.
    $seen = null;
    $monitor = NSEvent::addLocalMonitorForEventsMatchingMaskHandler(NSEventMask::APPLICATION_DEFINED, function (NSEvent $event) use ($app, $bar, &$seen): ?NSEvent {
        if ($event->subtype() !== 7) {
            return $event;
        }
        $release = $app->nextEventMatchingMaskUntilDateInModeDequeue(NSEventMask::LEFT_MOUSE_UP, null, NSDefaultRunLoopMode, false);
        $seen = [$bar->value(), ! is_null($release)];

        return null;
    });
    $marker = NSEvent::otherEventWithTypeLocationModifierFlagsTimestampWindowNumberContextSubtypeData1Data2(
        NSEventType::APPLICATION_DEFINED, new NSPoint(0.0, 0.0), 0, 0.0, $window->native()->windowNumber(), null, 7, 0, 0,
    );

    $app->postEventAtStart(mouseAt($window, $knob, NSEventType::LEFT_MOUSE_DOWN), false);
    $app->postEventAtStart(mouseAt($window, $down, NSEventType::LEFT_MOUSE_DRAGGED), false);
    $app->postEventAtStart($marker, false);
    $app->postEventAtStart(mouseAt($window, $down, NSEventType::LEFT_MOUSE_UP), false);
    pumpFor(0.3);
    NSEvent::removeMonitor($monitor);
    $mail = scrollMail();

    expect($seen)->not->toBeNull()
        ->and($seen[0])->toBeGreaterThan(300)
        ->and($seen[1])->toBeTrue()
        ->and($mail)->not->toBe([])
        ->and(end($mail)->value)->toBe($bar->value());
    $window->close();
});

it('moves step per wheel line over it, and a precise wheel one line per 10 points', function (): void {
    [$window, $bar] = scrollBarWindow();
    $bar->setStep(10);
    $over = canvasWindowPoint($window, $bar->native(), 4.0, 120.0);

    $window->canvasPointer()->seen(scrollEvent(new NSPoint(0.0, 0.0), CGScrollEventUnit::LINE, -3), $over);
    expect(scrollMail())->toEqual([new ScrollChanged('main', 'm.bar', $bar->uuid(), 30)])
        ->and(session()->withheld())->not->toBeNull();

    $window->canvasPointer()->seen(scrollEvent(new NSPoint(0.0, 0.0), CGScrollEventUnit::PIXEL, -25), $over);
    expect(array_map(fn (ScrollChanged $mail): int => $mail->value, scrollMail()))->toBe([50]);
    $window->canvasPointer()->seen(scrollEvent(new NSPoint(0.0, 0.0), CGScrollEventUnit::PIXEL, -5), $over);
    expect(array_map(fn (ScrollChanged $mail): int => $mail->value, scrollMail()))->toBe([60])
        ->and($bar->native()->doubleValue())->toEqualWithDelta(60 / 900, 0.0001);
    $window->close();
});

it('takes no clicks while disabled', function (): void {
    [$window, $bar] = scrollBarWindow();

    $bar->setEnabled(false);

    expect($bar->native()->isEnabled())->toBeFalse();
    $window->close();
});
