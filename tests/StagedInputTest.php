<?php

declare(strict_types=1);

use Jovian\Toolkits\Appkit\Windows\AppkitStagedWindow;
use Surface\Contracts\HumanInput\Key;
use Surface\Contracts\HumanInput\Modifiers;
use Surface\Contracts\HumanInput\MouseButton;
use Surface\Contracts\Windows\Mail\Staged\StagedDragged;
use Surface\Contracts\Windows\Mail\Staged\StagedKeyPressed;
use Surface\Contracts\Windows\Mail\Staged\StagedKeyReleased;
use Surface\Contracts\Windows\Mail\Staged\StagedMoved;
use Surface\Contracts\Windows\Mail\Staged\StagedPressed;
use Surface\Contracts\Windows\Mail\Staged\StagedReleased;
use Surface\Contracts\Windows\Mail\Staged\StagedTextCommitted;

/*
 * Opens a staged window on screen: a GUI run. Each event is made as the helpers in Pest.php make them
 * (a window number of the window's own) and handed to the window as the session tap and the focus
 * view hand them: seen() for the mouse, keyDown(), keyUp() and flagsChanged() for the keys.
 */

beforeEach(function (): void {
    driver()->closeAll();
    pumpFor(0.05);
    takeMail(session());
});

afterEach(fn () => driver()->closeAll());

/** The staged mail posted since the last read, latest-wins mail flushed first. */
function stagedInputMail(): array
{
    session()->flushLatest();

    return array_values(array_filter(takeMail(session()), fn (object $mail): bool => str_starts_with($mail->name(), 'staged.')));
}

/** A moved event at a window point, made as mouseAt() in Pest.php makes one for a toolkit window. */
function movedEvent(AppkitStagedWindow $window, NSPoint $at): NSEvent
{
    return NSEvent::mouseEventWithTypeLocationModifierFlagsTimestampWindowNumberContextEventNumberClickCountPressure(
        NSEventType::MOUSE_MOVED, $at, 0, 0.0, $window->native()->windowNumber(), null, 0, 1, 1.0,
    );
}

/** Hands a mouse event to the window as the tap does. */
function seeMouse(AppkitStagedWindow $window, NSEvent $event): void
{
    $window->seen($event, $event->locationInWindow());
}

it('posts a press with the native click count, a drag, and the release', function (): void {
    $window = inputWindow()->watchInput();
    takeMail(session());
    $none = new Modifiers();

    seeMouse($window, clickEvent($window, NSEventType::LEFT_MOUSE_DOWN, 30.0, 40.0));
    seeMouse($window, clickEvent($window, NSEventType::LEFT_MOUSE_DRAGGED, 35.0, 45.0));
    seeMouse($window, clickEvent($window, NSEventType::LEFT_MOUSE_UP, 35.0, 45.0));

    expect(stagedInputMail())->toEqual([
        new StagedPressed('keys', 30.0, 40.0, MouseButton::LEFT, 1, $none),
        new StagedDragged('keys', 35.0, 45.0, MouseButton::LEFT, $none),
        new StagedReleased('keys', 35.0, 45.0, MouseButton::LEFT, $none),
    ]);
});

it('lets the first button own the press and ignores a press outside the content', function (): void {
    $window = inputWindow()->watchInput();
    takeMail(session());

    seeMouse($window, clickEvent($window, NSEventType::LEFT_MOUSE_DOWN, 10.0, 10.0));
    seeMouse($window, clickEvent($window, NSEventType::RIGHT_MOUSE_DOWN, 20.0, 20.0));
    seeMouse($window, clickEvent($window, NSEventType::RIGHT_MOUSE_UP, 20.0, 20.0));
    seeMouse($window, clickEvent($window, NSEventType::LEFT_MOUSE_UP, 10.0, 10.0));
    seeMouse($window, clickEvent($window, NSEventType::LEFT_MOUSE_DOWN, -8.0, 10.0));

    expect(array_map(fn (object $mail): string => $mail->name(), stagedInputMail()))->toBe(['staged.pressed.keys', 'staged.released.keys']);
});

it('posts a move over the content as latest mail', function (): void {
    $window = inputWindow()->watchInput();
    takeMail(session());
    $at = clickEvent($window, NSEventType::LEFT_MOUSE_DOWN, 50.0, 60.0)->locationInWindow();

    seeMouse($window, movedEvent($window, $at));

    expect(stagedInputMail())->toEqual([new StagedMoved('keys', 50.0, 60.0, new Modifiers())]);
});

it('posts a key, the text it composes, and the key release', function (): void {
    $window = inputWindow()->watchInput();
    takeMail(session());

    $window->keyDown(keyEvent($window, 13, true, 'w'));
    $window->keyUp(keyEvent($window, 13, false, 'w'));

    expect(stagedInputMail())->toEqual([
        new StagedKeyPressed('keys', Key::W, 'w', new Modifiers(), false),
        new StagedTextCommitted('keys', 'w'),
        new StagedKeyReleased('keys', Key::W, new Modifiers()),
    ]);
});

it('posts a lone modifier key as a press and a release', function (): void {
    $window = inputWindow()->watchInput();
    takeMail(session());

    $window->flagsChanged(flagsEvent($window, 56, NSEventModifierFlags::SHIFT->value));
    $window->flagsChanged(flagsEvent($window, 56, 0));

    expect(stagedInputMail())->toEqual([
        new StagedKeyPressed('keys', Key::LEFT_SHIFT, '', new Modifiers(shift: true), false),
        new StagedKeyReleased('keys', Key::LEFT_SHIFT, new Modifiers()),
    ]);
});

it('posts nothing while it does not watch input, and nothing once it stops', function (): void {
    $window = inputWindow();
    takeMail(session());

    seeMouse($window, clickEvent($window, NSEventType::LEFT_MOUSE_DOWN, 30.0, 40.0));
    $window->watchInput();
    $window->watchInput(false);
    seeMouse($window, clickEvent($window, NSEventType::LEFT_MOUSE_UP, 30.0, 40.0));

    expect(stagedInputMail())->toBe([]);
});
