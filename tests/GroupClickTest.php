<?php

declare(strict_types=1);

use Surface\Contracts\Windows\Mail\View\GroupClicked;
use Windows\AppkitWindow;

beforeEach(function (): void {
    driver()->closeAll();
    pumpFor(0.05);
    takeMail(session());
});

/** The window (base) point of $view's centre, once laid out. */
function groupClickPoint(AppkitWindow $window, NSView $view): NSPoint
{
    $window->present();
    pumpUntil(fn (): bool => $view->bounds()->width > 0.0, 3.0);
    // window → view is a translation, with y turned round when the view is flipped: invert it.
    $origin = $view->convertPointFromView(new NSPoint(0.0, 0.0), null);
    $sign = $view->convertPointFromView(new NSPoint(0.0, 1.0), null)->y - $origin->y;
    $x = $view->bounds()->width / 2;
    $y = $view->bounds()->height / 2;

    return new NSPoint($x - $origin->x, ($y - $origin->y) / $sign);
}

function primaryEvent(AppkitWindow $window, NSPoint $at, NSEventType $type, int $flags = 0): NSEvent
{
    return NSEvent::mouseEventWithTypeLocationModifierFlagsTimestampWindowNumberContextEventNumberClickCountPressure(
        $type, $at, $flags, 0.0, $window->native()->windowNumber(), null, 0, 1, 1.0,
    );
}

/** @return list<GroupClicked> */
function groupClickMail(): array
{
    return array_values(array_filter(takeMail(session()), fn (object $mail): bool => $mail instanceof GroupClicked));
}

function clickAt(AppkitWindow $window, NSPoint $down, ?NSPoint $up = null, int $flags = 0): void
{
    session()->see(primaryEvent($window, $down, NSEventType::LEFT_MOUSE_DOWN, $flags));
    session()->see(primaryEvent($window, $up ?? $down, NSEventType::LEFT_MOUSE_UP, $flags));
}

it('posts view.group-clicked for a press and release on a label inside a clickable group', function (): void {
    $window = driver()->open('main', 300, 200);
    $card = $window->column('m')->column('card')->setClickable();
    $title = $card->label('title', 'Stargazer');
    $at = groupClickPoint($window, $title->native());
    takeMail(session());

    clickAt($window, $at);

    $mail = groupClickMail();
    expect($mail)->toHaveCount(1)
        ->and([$mail[0]->window, $mail[0]->path, $mail[0]->uuid])->toBe(['main', 'm.card', $card->uuid()]);
});

it('posts nothing for a group that is not clickable, a Ctrl-click, or a release elsewhere', function (): void {
    $window = driver()->open('main', 300, 200);
    $m = $window->column('m');
    $plain = $m->column('plain');
    $text = $plain->label('text', 'Plain');
    $card = $m->column('card')->setClickable();
    $title = $card->label('title', 'Stargazer');
    $at = groupClickPoint($window, $title->native());
    $elsewhere = groupClickPoint($window, $text->native());
    takeMail(session());

    clickAt($window, $elsewhere);
    clickAt($window, $at, null, NSEventModifierFlags::CONTROL->value);
    clickAt($window, $at, $elsewhere);

    expect(groupClickMail())->toBe([]);
});

it('posts nothing for a click on a window behind its sheet, and posts again once the sheet is gone', function (): void {
    $window = driver()->open('main', 300, 200);
    $card = $window->column('m')->column('card')->setClickable();
    $title = $card->label('title', 'Stargazer');
    $at = groupClickPoint($window, $title->native());
    $sheet = driver()->open('sheet', 200, 100, null, $window);
    $sheet->present();
    pumpUntil(fn (): bool => $window->hasSheet(), 3.0);
    takeMail(session());

    clickAt($window, $at);
    expect(groupClickMail())->toBe([]);

    $sheet->close();
    pumpUntil(fn (): bool => ! $window->hasSheet(), 3.0);
    takeMail(session());
    clickAt($window, $at);
    expect(groupClickMail())->toHaveCount(1);
});
