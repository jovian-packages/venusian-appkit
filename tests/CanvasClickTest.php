<?php

declare(strict_types=1);

use Jovian\Toolkits\Appkit\Windows\AppkitWindow;
use Surface\Contracts\Windows\Mail\View\CanvasClicked;

beforeEach(function (): void {
    driver()->closeAll();
    pumpFor(0.05);
    takeMail(session());
});

/** The window (base) point of ($x, $y) measured from $view's top-left, once laid out. */
function canvasWindowPoint(AppkitWindow $window, NSView $view, float $x, float $y): NSPoint
{
    $window->present();
    pumpUntil(fn (): bool => $view->bounds()->width > 0.0, 3.0);
    // window → view is a translation, with y turned round when the view is flipped: invert it.
    $origin = $view->convertPointFromView(new NSPoint(0.0, 0.0), null);
    $sign = $view->convertPointFromView(new NSPoint(0.0, 1.0), null)->y - $origin->y;
    $ly = $view->isFlipped() ? $y : $view->bounds()->height - $y;

    return new NSPoint($x - $origin->x, ($ly - $origin->y) / $sign);
}

function mouseAt(AppkitWindow $window, NSPoint $at, NSEventType $type, int $flags = 0): NSEvent
{
    return NSEvent::mouseEventWithTypeLocationModifierFlagsTimestampWindowNumberContextEventNumberClickCountPressure(
        $type, $at, $flags, 0.0, $window->native()->windowNumber(), null, 0, 1, 1.0,
    );
}

/** @return list<CanvasClicked> the canvas clicks posted since the last read */
function appkitCanvasClicks(): array
{
    return array_values(array_filter(takeMail(session()), fn (object $mail): bool => $mail instanceof CanvasClicked));
}

it('posts view.clicked for a press and release on a canvas, at the press, from its top-left', function (): void {
    $window = driver()->open('main', 300, 240);
    $board = $window->column('m')->canvas('board')->minSize(200, 120);
    $at = canvasWindowPoint($window, $board->native(), 12.0, 34.0);
    takeMail(session());
    session()->see(mouseAt($window, $at, NSEventType::LEFT_MOUSE_DOWN));
    session()->see(mouseAt($window, new NSPoint($at->x + 1.0, $at->y), NSEventType::LEFT_MOUSE_DRAGGED));
    session()->see(mouseAt($window, new NSPoint($at->x + 1.0, $at->y), NSEventType::LEFT_MOUSE_UP));

    $mail = appkitCanvasClicks();
    expect($mail)->toHaveCount(1)
        ->and([$mail[0]->name(), $mail[0]->uuid])->toBe(['view.clicked.main.m.board', $board->uuid()])
        ->and([round($mail[0]->x), round($mail[0]->y)])->toBe([12.0, 34.0]);
});

it('posts nothing for a drag, a Ctrl-click, a release beside the canvas, or a disabled canvas', function (): void {
    $window = driver()->open('main', 300, 240);
    $column = $window->column('m');
    $board = $column->canvas('board')->minSize(200, 120);
    $beside = $column->label('beside', 'Beside');
    $at = canvasWindowPoint($window, $board->native(), 20.0, 20.0);
    $off = canvasWindowPoint($window, $beside->native(), 2.0, 2.0);
    takeMail(session());

    session()->see(mouseAt($window, $at, NSEventType::LEFT_MOUSE_DOWN));
    session()->see(mouseAt($window, new NSPoint($at->x + 6.0, $at->y), NSEventType::LEFT_MOUSE_DRAGGED));
    session()->see(mouseAt($window, $at, NSEventType::LEFT_MOUSE_UP));
    session()->see(mouseAt($window, $at, NSEventType::LEFT_MOUSE_DOWN, NSEventModifierFlags::CONTROL->value));
    session()->see(mouseAt($window, $at, NSEventType::LEFT_MOUSE_UP, NSEventModifierFlags::CONTROL->value));
    session()->see(mouseAt($window, $at, NSEventType::LEFT_MOUSE_DOWN));
    session()->see(mouseAt($window, $off, NSEventType::LEFT_MOUSE_UP));
    $column->disable();
    session()->see(mouseAt($window, $at, NSEventType::LEFT_MOUSE_DOWN));
    session()->see(mouseAt($window, $at, NSEventType::LEFT_MOUSE_UP));

    expect(appkitCanvasClicks())->toBe([]);
});

it('sees clicks the session pumps, and stops when its window closes', function (): void {
    $window = driver()->open('main', 300, 240);
    $board = $window->column('m')->canvas('board')->minSize(200, 120);
    $at = canvasWindowPoint($window, $board->native(), 20.0, 20.0);
    takeMail(session());
    session()->application()->postEventAtStart(mouseAt($window, $at, NSEventType::LEFT_MOUSE_UP), true);
    session()->application()->postEventAtStart(mouseAt($window, $at, NSEventType::LEFT_MOUSE_DOWN), true);
    pumpFor(0.2);
    $pumped = array_map(fn (CanvasClicked $mail): string => $mail->name(), appkitCanvasClicks());
    $clicks = $window->canvasClicks();
    $window->close();
    pumpFor(0.05);

    expect($pumped)->toBe(['view.clicked.main.m.board'])
        ->and($clicks->watching())->toBeFalse();
});
