<?php

declare(strict_types=1);

use Jovian\Toolkits\Appkit\Windows\AppkitWindow;
use Surface\Contracts\Windows\Mail\View\CanvasDragged;
use Surface\Contracts\Windows\Mail\View\CanvasEntered;
use Surface\Contracts\Windows\Mail\View\CanvasLeft;
use Surface\Contracts\Windows\Mail\View\CanvasMoved;
use Surface\Contracts\Windows\Mail\View\CanvasPressed;
use Surface\Contracts\Windows\Mail\View\CanvasReleased;

beforeEach(function (): void {
    driver()->closeAll();
    pumpFor(0.05);
    takeMail(session());
});

/** A window with a 200 × 120 canvas 'board' above a label, watching unless told not to. */
function hoverWindow(bool $watch = true): array
{
    $window = driver()->open('main', 300, 260);
    $column = $window->column('m');
    $board = $column->canvas('board')->minSize(200, 120);
    $column->label('below', 'below');
    if ($watch) {
        $board->watchPointer();
    }
    canvasWindowPoint($window, $board->native(), 0.0, 0.0);
    takeMail(session());

    return [$window, $board];
}

/** Each event through the window's pointer then hover taps, in order, as the session shows them. */
function hoverSee(AppkitWindow $window, array $events): void
{
    foreach ($events as [$event, $at]) {
        $window->canvasPointer()->seen($event, $at);
        $window->canvasHover()->seen($event, $at);
    }
}

/** The hover and press mail posted since the last read, latest-wins mail flushed first. */
function hoverKinds(): array
{
    session()->flushLatest();
    $mail = array_filter(takeMail(session()), fn (object $mail): bool => $mail instanceof CanvasEntered || $mail instanceof CanvasMoved
        || $mail instanceof CanvasLeft || $mail instanceof CanvasPressed || $mail instanceof CanvasDragged || $mail instanceof CanvasReleased);

    return array_values(array_map(fn (object $mail): string => (new ReflectionClass($mail))->getShortName(), $mail));
}

it('carries a tracking area for its visible rect that sends enters, exits and moves', function (): void {
    [$window, $board] = hoverWindow();
    $area = $board->trackingArea();
    $options = NSTrackingAreaOptions::MOUSE_ENTERED_AND_EXITED->value | NSTrackingAreaOptions::MOUSE_MOVED->value
        | NSTrackingAreaOptions::ACTIVE_IN_ACTIVE_APP->value | NSTrackingAreaOptions::IN_VISIBLE_RECT->value;

    expect($area->options())->toBe($options)
        ->and($area->owner())->toBe($board->native());
    $window->close();
});

it('posts Entered, Moved and Left as the pointer crosses a watching canvas', function (): void {
    [$window, $board] = hoverWindow();
    $inside = canvasWindowPoint($window, $board->native(), 20.0, 30.0);
    $further = canvasWindowPoint($window, $board->native(), 25.0, 30.0);
    $outside = canvasWindowPoint($window, $board->native(), 20.0, 200.0);

    hoverSee($window, [[moveEvent(new NSPoint(0.0, 0.0)), $inside]]);
    session()->flushLatest();
    $entered = takeMail(session());
    hoverSee($window, [[moveEvent(new NSPoint(0.0, 0.0)), $further], [moveEvent(new NSPoint(0.0, 0.0)), $outside]]);

    expect(array_map(fn (object $mail): string => (new ReflectionClass($mail))->getShortName(), array_values(array_filter($entered, fn (object $mail): bool => $mail instanceof CanvasEntered || $mail instanceof CanvasMoved))))->toBe(['CanvasEntered', 'CanvasMoved'])
        ->and(array_values(array_filter($entered, fn (object $mail): bool => $mail instanceof CanvasMoved))[0])->toEqual(new CanvasMoved('main', 'm.board', $board->uuid(), 20.0, 30.0, new Surface\Contracts\HumanInput\Modifiers()))
        ->and(hoverKinds())->toBe(['CanvasMoved', 'CanvasLeft']);
    $window->close();
});

it('posts nothing from a canvas that is not watching', function (): void {
    [$window, $board] = hoverWindow(watch: false);
    $inside = canvasWindowPoint($window, $board->native(), 20.0, 30.0);
    $outside = canvasWindowPoint($window, $board->native(), 20.0, 200.0);

    hoverSee($window, [[moveEvent(new NSPoint(0.0, 0.0)), $inside], [moveEvent(new NSPoint(0.0, 0.0)), $outside]]);

    expect(hoverKinds())->toBe([]);
    $window->close();
});

it('posts no hover during an owned press, and Left after Released for a release outside', function (): void {
    [$window, $board] = hoverWindow();
    $inside = canvasWindowPoint($window, $board->native(), 20.0, 30.0);
    $outside = canvasWindowPoint($window, $board->native(), 20.0, 200.0);
    hoverSee($window, [[moveEvent(new NSPoint(0.0, 0.0)), $inside]]);
    session()->flushLatest();
    takeMail(session());

    hoverSee($window, [
        [mouseAt($window, $inside, NSEventType::LEFT_MOUSE_DOWN), $inside],
        [mouseAt($window, $outside, NSEventType::LEFT_MOUSE_DRAGGED), $outside],
        [mouseAt($window, $outside, NSEventType::LEFT_MOUSE_UP), $outside],
    ]);

    expect(hoverKinds())->toBe(['CanvasPressed', 'CanvasDragged', 'CanvasReleased', 'CanvasLeft']);
    $window->close();
});

it('posts nothing for a removed canvas', function (): void {
    [$window, $board] = hoverWindow();
    $inside = canvasWindowPoint($window, $board->native(), 20.0, 30.0);
    $outside = canvasWindowPoint($window, $board->native(), 20.0, 200.0);
    hoverSee($window, [[moveEvent(new NSPoint(0.0, 0.0)), $inside]]);
    session()->flushLatest();
    takeMail(session());

    $board->remove();
    hoverSee($window, [[moveEvent(new NSPoint(0.0, 0.0)), $outside]]);

    expect(hoverKinds())->toBe([]);
    $window->close();
});
