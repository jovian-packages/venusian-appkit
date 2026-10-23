<?php

declare(strict_types=1);

use Jovian\Toolkits\Appkit\Windows\AppkitWindow;
use Surface\Contracts\HumanInput\Modifiers;
use Surface\Contracts\HumanInput\MouseButton;
use Surface\Contracts\Windows\Mail\View\CanvasClicked;
use Surface\Contracts\Windows\Mail\View\CanvasDragged;
use Surface\Contracts\Windows\Mail\View\CanvasPressed;
use Surface\Contracts\Windows\Mail\View\CanvasReleased;
use Surface\Contracts\Windows\Mail\View\CanvasWheeled;
use Surface\Contracts\Windows\Mail\View\RightClicked;
use Surface\Windows\Primitives\TKCanvas;
use Surface\Windows\Primitives\TKPrimitiveGroup;

beforeEach(function (): void {
    driver()->closeAll();
    pumpFor(0.05);
    takeMail(session());
});

/**
 * A window with a 200 × 120 canvas 'board' in column 'm', watching unless told not to.
 *
 * @return array{AppkitWindow, TKPrimitiveGroup, TKCanvas}
 */
function appkitPointerWindow(bool $watch = true): array
{
    $window = driver()->open('main', 300, 240);
    $column = $window->column('m');
    $board = $column->canvas('board')->minSize(200, 120);
    if ($watch) {
        $board->watchPointer();
    }

    return [$window, $column, $board];
}

/** @return list<object> the pointer, click and right-click mail posted since the last read */
function appkitPointerMail(): array
{
    return array_values(array_filter(takeMail(session()), fn (object $mail): bool => $mail instanceof CanvasPressed
        || $mail instanceof CanvasDragged || $mail instanceof CanvasReleased || $mail instanceof CanvasWheeled
        || $mail instanceof CanvasClicked || $mail instanceof RightClicked));
}

/** @return list<string> each mail's class short name, button where it has one */
function appkitPointerKinds(array $mail): array
{
    return array_map(fn (object $m): string => (new ReflectionClass($m))->getShortName().(property_exists($m, 'button') ? ':'.$m->button->value : ''), $mail);
}

/** The see() of each built event, in order. */
function seeAll(NSEvent ...$events): void
{
    foreach ($events as $event) {
        session()->see($event);
    }
}

it('posts no pointer mail from a canvas that is not watching, and its click as before', function (): void {
    [$window, , $board] = appkitPointerWindow(watch: false);
    $at = canvasWindowPoint($window, $board->native(), 12.0, 34.0);
    takeMail(session());
    seeAll(
        mouseAt($window, $at, NSEventType::LEFT_MOUSE_DOWN),
        mouseAt($window, new NSPoint($at->x + 1.0, $at->y), NSEventType::LEFT_MOUSE_DRAGGED),
        mouseAt($window, new NSPoint($at->x + 1.0, $at->y), NSEventType::LEFT_MOUSE_UP),
    );

    expect(appkitPointerKinds(appkitPointerMail()))->toBe(['CanvasClicked'])
        ->and($board->isWatchingPointer())->toBeFalse();
});

it('posts the press, each move that changed the point, and the release, in canvas coordinates', function (): void {
    [$window, , $board] = appkitPointerWindow();
    $at = canvasWindowPoint($window, $board->native(), 12.0, 34.0);
    $moved = canvasWindowPoint($window, $board->native(), 18.0, 34.0);
    takeMail(session());
    seeAll(
        mouseAt($window, $at, NSEventType::LEFT_MOUSE_DOWN),
        mouseAt($window, $moved, NSEventType::LEFT_MOUSE_DRAGGED),
        mouseAt($window, $moved, NSEventType::LEFT_MOUSE_DRAGGED),
        mouseAt($window, $moved, NSEventType::LEFT_MOUSE_UP),
    );

    $mail = appkitPointerMail();
    expect(appkitPointerKinds($mail))->toBe(['CanvasPressed:left', 'CanvasDragged:left', 'CanvasReleased:left'])
        ->and(array_map(fn (object $m): string => $m->name(), $mail))->toBe(['view.pressed.main.m.board', 'view.dragged.main.m.board', 'view.released.main.m.board'])
        ->and(array_map(fn (object $m): array => [round($m->x), round($m->y)], $mail))->toBe([[12.0, 34.0], [18.0, 34.0], [18.0, 34.0]])
        ->and([$mail[0]->uuid, $mail[0]->clicks])->toBe([$board->uuid(), 1])
        ->and($mail[0]->modifiers)->toEqual(new Modifiers());
});

it('carries AppKit\'s click count and the modifiers held', function (): void {
    [$window, , $board] = appkitPointerWindow();
    $at = canvasWindowPoint($window, $board->native(), 20.0, 20.0);
    takeMail(session());
    $flags = NSEventModifierFlags::SHIFT->value | NSEventModifierFlags::COMMAND->value;
    seeAll(mouseAt($window, $at, NSEventType::LEFT_MOUSE_DOWN, $flags, clicks: 3), mouseAt($window, $at, NSEventType::LEFT_MOUSE_UP, NSEventModifierFlags::OPTION->value));

    $mail = appkitPointerMail();
    // The clicks tap runs first: the click posts before the release.
    expect(appkitPointerKinds($mail))->toBe(['CanvasPressed:left', 'CanvasClicked', 'CanvasReleased:left'])
        ->and($mail[0]->clicks)->toBe(3)
        ->and($mail[0]->modifiers)->toEqual(new Modifiers(shift: true, meta: true))
        ->and($mail[2]->modifiers)->toEqual(new Modifiers(alt: true));
});

it('keeps posting the drag and the release outside the canvas and the window', function (): void {
    [$window, , $board] = appkitPointerWindow();
    $at = canvasWindowPoint($window, $board->native(), 20.0, 20.0);
    $outside = new NSPoint(-40.0, -30.0);   // left of and below the window's content
    takeMail(session());
    seeAll(
        mouseAt($window, $at, NSEventType::LEFT_MOUSE_DOWN),
        mouseAt($window, $outside, NSEventType::LEFT_MOUSE_DRAGGED),
        mouseAt($window, $outside, NSEventType::LEFT_MOUSE_UP),
    );

    $mail = appkitPointerMail();
    expect(appkitPointerKinds($mail))->toBe(['CanvasPressed:left', 'CanvasDragged:left', 'CanvasReleased:left'])
        ->and($mail[1]->x)->toBeLessThan(0.0)
        ->and($mail[1]->y)->toBeGreaterThan(120.0)
        ->and([$mail[2]->x, $mail[2]->y])->toBe([$mail[1]->x, $mail[1]->y]);
});

it('posts nothing for a second button pressed and released during a drag', function (): void {
    [$window, , $board] = appkitPointerWindow();
    $at = canvasWindowPoint($window, $board->native(), 20.0, 20.0);
    $moved = canvasWindowPoint($window, $board->native(), 30.0, 20.0);
    takeMail(session());
    seeAll(
        mouseAt($window, $at, NSEventType::LEFT_MOUSE_DOWN),
        mouseAt($window, $at, NSEventType::RIGHT_MOUSE_DOWN),
        mouseAt($window, $moved, NSEventType::LEFT_MOUSE_DRAGGED),
        mouseAt($window, $moved, NSEventType::RIGHT_MOUSE_UP),
        mouseAt($window, $moved, NSEventType::LEFT_MOUSE_UP),
    );

    expect(appkitPointerKinds(appkitPointerMail()))->toBe(['CanvasPressed:left', 'RightClicked', 'CanvasDragged:left', 'CanvasReleased:left']);
});

it('posts a right press, its drag and its release on a canvas with no context menu', function (): void {
    [$window, , $board] = appkitPointerWindow();
    $at = canvasWindowPoint($window, $board->native(), 20.0, 20.0);
    $moved = canvasWindowPoint($window, $board->native(), 25.0, 22.0);
    takeMail(session());
    seeAll(
        mouseAt($window, $at, NSEventType::RIGHT_MOUSE_DOWN),
        mouseAt($window, $moved, NSEventType::RIGHT_MOUSE_DRAGGED),
        mouseAt($window, $moved, NSEventType::RIGHT_MOUSE_UP),
    );

    expect(appkitPointerKinds(appkitPointerMail()))->toBe(['RightClicked', 'CanvasPressed:right', 'CanvasDragged:right', 'CanvasReleased:right']);
});

it('releases the press where it landed when a context menu opens for it, and nothing after', function (): void {
    [$window, , $board] = appkitPointerWindow();
    $board->setContextMenu([['label' => 'Mark']]);
    $at = canvasWindowPoint($window, $board->native(), 24.0, 36.0);
    takeMail(session());
    $done = whileTracking(function () use ($window): void {
        $window->rightClicks()->openMenu()->menu()->cancelTracking();
    });
    $press = mouseAt($window, $at, NSEventType::RIGHT_MOUSE_DOWN);
    session()->see($press);
    $done();
    $withheld = session()->withheld();
    $during = appkitPointerMail();
    session()->see(mouseAt($window, $at, NSEventType::RIGHT_MOUSE_UP));

    expect($withheld)->toBe($press)
        ->and(appkitPointerKinds($during))->toBe(['RightClicked', 'CanvasPressed:right', 'CanvasReleased:right'])
        ->and([round($during[2]->x), round($during[2]->y)])->toBe([24.0, 36.0])
        ->and(appkitPointerMail())->toBe([]);
});

it('ends an owned press at its last point when another press opens a menu', function (): void {
    [$window, , $board] = appkitPointerWindow();
    $at = canvasWindowPoint($window, $board->native(), 20.0, 20.0);
    $moved = canvasWindowPoint($window, $board->native(), 40.0, 20.0);
    takeMail(session());
    seeAll(mouseAt($window, $at, NSEventType::LEFT_MOUSE_DOWN), mouseAt($window, $moved, NSEventType::LEFT_MOUSE_DRAGGED));
    $right = mouseAt($window, $moved, NSEventType::RIGHT_MOUSE_DOWN);
    session()->withhold($right);
    $window->canvasPointer()->seen($right, $moved);
    session()->withhold(null);
    $ended = appkitPointerMail();
    session()->see(mouseAt($window, $moved, NSEventType::LEFT_MOUSE_UP));

    expect(appkitPointerKinds($ended))->toBe(['CanvasPressed:left', 'CanvasDragged:left', 'CanvasReleased:left'])
        ->and([round($ended[2]->x), round($ended[2]->y)])->toBe([40.0, 20.0])
        ->and(appkitPointerMail())->toBe([]);
});

it('reads the other buttons by number', function (): void {
    [$window, , $board] = appkitPointerWindow();
    $at = canvasWindowPoint($window, $board->native(), 20.0, 20.0);
    takeMail(session());
    $pointer = $window->canvasPointer();
    foreach ([2, 3, 4] as $number) {
        $pointer->seen(otherButtonEvent(new NSPoint(0.0, 0.0), $number, true), $at);
        $pointer->seen(otherButtonEvent(new NSPoint(0.0, 0.0), $number, false), $at);
    }
    $pointer->seen(otherButtonEvent(new NSPoint(0.0, 0.0), 5, true), $at);

    expect(appkitPointerKinds(appkitPointerMail()))->toBe([
        'CanvasPressed:middle', 'CanvasReleased:middle', 'CanvasPressed:x1', 'CanvasReleased:x1', 'CanvasPressed:x2', 'CanvasReleased:x2',
    ]);
});

it('posts the wheel over a watching canvas and keeps the event; nothing over a canvas that is not watching', function (): void {
    [$window, $column, $board] = appkitPointerWindow();
    $idle = $column->canvas('idle')->minSize(200, 40);
    $at = canvasWindowPoint($window, $board->native(), 20.0, 20.0);
    $over = canvasWindowPoint($window, $idle->native(), 20.0, 20.0);
    takeMail(session());
    $pointer = $window->canvasPointer();
    $notch = scrollEvent(new NSPoint(0.0, 0.0), CGScrollEventUnit::LINE, -1);
    $pointer->seen($notch, $at);
    $kept = session()->withheld();
    $pixels = scrollEvent(new NSPoint(0.0, 0.0), CGScrollEventUnit::PIXEL, -3, 2);
    $pointer->seen($pixels, $at);
    $mail = appkitPointerMail();
    session()->withhold(null);
    $pointer->seen(scrollEvent(new NSPoint(0.0, 0.0), CGScrollEventUnit::LINE, -1), $over);

    expect($kept)->toBe($notch)
        ->and(appkitPointerKinds($mail))->toBe(['CanvasWheeled', 'CanvasWheeled'])
        ->and([$mail[0]->name(), $mail[0]->dx, $mail[0]->dy, $mail[0]->precise])->toBe(['view.wheeled.main.m.board', 0.0, 1.0, false])
        ->and([$mail[1]->dx, $mail[1]->dy, $mail[1]->precise])->toBe([-2.0, 3.0, true])
        ->and(appkitPointerMail())->toBe([])
        ->and(session()->withheld())->toBeNull();
});

it('keeps the wheel from the scroll view a watching canvas sits in', function (): void {
    $window = driver()->open('main', 300, 240);
    $content = $window->column('m')->scrollView('s')->fill()->column('c');
    $board = $content->canvas('board')->minSize(200, 120)->watchPointer();
    $content->label('tall', str_repeat("line\n", 80));
    $at = canvasWindowPoint($window, $board->native(), 20.0, 20.0);
    takeMail(session());
    $wheel = scrollEvent(new NSPoint(0.0, 0.0), CGScrollEventUnit::LINE, -1);
    $window->canvasPointer()->seen($wheel, $at);
    $kept = session()->withheld();
    session()->withhold(null);
    $board->watchPointer(false);
    $window->canvasPointer()->seen($wheel, $at);

    // A withheld event is never dispatched (RightClickTest pins the pump), so the scroll view never sees it.
    expect($kept)->toBe($wheel)
        ->and(session()->withheld())->toBeNull()
        ->and(appkitPointerKinds(appkitPointerMail()))->toBe(['CanvasWheeled']);
});

it('drops the press silently when code ends it, and starts fresh on the next press', function (Closure $end, Closure $restore): void {
    [$window, $column, $board] = appkitPointerWindow();
    $at = canvasWindowPoint($window, $board->native(), 20.0, 20.0);
    $moved = canvasWindowPoint($window, $board->native(), 30.0, 20.0);
    takeMail(session());
    session()->see(mouseAt($window, $at, NSEventType::LEFT_MOUSE_DOWN));
    $end($board, $column);
    seeAll(mouseAt($window, $moved, NSEventType::LEFT_MOUSE_DRAGGED), mouseAt($window, $moved, NSEventType::LEFT_MOUSE_UP));
    $after = appkitPointerMail();
    $restore($board, $column);
    session()->see(mouseAt($window, $at, NSEventType::RIGHT_MOUSE_DOWN));

    expect(appkitPointerKinds($after))->toBe(['CanvasPressed:left'])
        ->and(appkitPointerKinds(appkitPointerMail()))->toBe(['RightClicked', 'CanvasPressed:right']);
})->with([
    'stops watching' => [fn (TKCanvas $b) => $b->watchPointer(false), fn (TKCanvas $b) => $b->watchPointer()],
    'canvas disabled' => [fn (TKCanvas $b) => $b->setEnabled(false), fn (TKCanvas $b) => $b->setEnabled(true)],
    'group disabled' => [fn (TKCanvas $b, TKPrimitiveGroup $c) => $c->setEnabled(false), fn (TKCanvas $b, TKPrimitiveGroup $c) => $c->setEnabled(true)],
]);

it('drops the press silently when its canvas is removed', function (): void {
    [$window, , $board] = appkitPointerWindow();
    $at = canvasWindowPoint($window, $board->native(), 20.0, 20.0);
    takeMail(session());
    session()->see(mouseAt($window, $at, NSEventType::LEFT_MOUSE_DOWN));
    $board->remove();
    seeAll(mouseAt($window, $at, NSEventType::LEFT_MOUSE_DRAGGED), mouseAt($window, $at, NSEventType::LEFT_MOUSE_UP));

    expect(appkitPointerKinds(appkitPointerMail()))->toBe(['CanvasPressed:left']);
});

it('sees what the session pumps, and stops when its window closes', function (): void {
    [$window, , $board] = appkitPointerWindow();
    $at = canvasWindowPoint($window, $board->native(), 20.0, 20.0);
    $moved = canvasWindowPoint($window, $board->native(), 30.0, 20.0);
    takeMail(session());
    foreach ([[$moved, NSEventType::LEFT_MOUSE_UP], [$moved, NSEventType::LEFT_MOUSE_DRAGGED], [$at, NSEventType::LEFT_MOUSE_DOWN]] as [$point, $type]) {
        session()->application()->postEventAtStart(mouseAt($window, $point, $type), true);
    }
    pumpFor(0.2);
    $pumped = appkitPointerKinds(appkitPointerMail());
    $pointer = $window->canvasPointer();
    $window->close();
    pumpFor(0.05);

    expect($pumped)->toBe(['CanvasPressed:left', 'CanvasDragged:left', 'CanvasReleased:left'])
        ->and($pointer->watching())->toBeFalse();
});
