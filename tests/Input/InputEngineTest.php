<?php

declare(strict_types=1);

use Jovian\Toolkits\Appkit\Bridge\AppkitBridgeDriver;
use Jovian\Toolkits\Appkit\Input\AppkitInputEngine;
use Surface\Contracts\HumanInput\HumanInputException;
use Surface\Contracts\HumanInput\Key;
use Surface\Contracts\HumanInput\Modifiers;
use Surface\Contracts\HumanInput\MouseButton;
use Voyager\Config\Repository;
use Voyager\Vessel\ControlPanel;

/*
 * The engine over the real AppKit session, fed with posted NSEvents: the session pumps them as
 * the loop's poller does and shows each to the engine before sending it. Windows are on screen
 * (the window-at-point lookup finds only those). The pointer location is the test's.
 */

afterEach(function (): void {
    letGoOfInput();
    driver()->closeAllStaged();
    pumpFor(0.05);
});

it('throws from connect when its driver has no connected session', function (): void {
    $container = new ControlPanel();
    $container->registerInstance('config', new Repository(['windows' => ['about' => [], 'default_menu' => null, 'menus' => []]]));
    $engine = new AppkitInputEngine(inputFrame(), new AppkitBridgeDriver($container));

    expect(fn () => $engine->connect())->toThrow(HumanInputException::class, 'appkit: no connected AppKit session')
        ->and($engine->connected())->toBeFalse();
});

it('reads keys, text and modifiers, a repeat typing again and changing nothing else', function (): void {
    $window = inputWindow();
    $engine = appkitInput();
    $shift = NSEventModifierFlags::SHIFT->value | 0x2;

    deliver($engine,
        keyEvent($window, 0x0D, true, 'W', $shift),
        keyEvent($window, 0x0D, true, 'W', $shift, repeat: true),
    );

    expect($engine->keyboard()->pressedKeys())->toBe([Key::W])
        ->and($engine->keyboard()->text())->toBe('WW')
        ->and($engine->keyboard()->modifiers())->toEqual(new Modifiers(shift: true));

    deliver($engine, keyEvent($window, 0x0D, false));

    expect($engine->keyboard()->isDown(Key::W))->toBeFalse()
        ->and($engine->keyboard()->wasReleased(Key::W))->toBeTrue()
        ->and($engine->keyboard()->modifiers())->toEqual(new Modifiers());
});

it('releases a key let go while Command is held', function (): void {
    // AppKit's sendEvent: keeps a Command-held key-up from the window; the tap reads it first.
    $window = inputWindow();
    $engine = appkitInput();
    $command = NSEventModifierFlags::COMMAND->value | 0x8;

    deliver($engine, flagsEvent($window, 0x37, $command), keyEvent($window, 0x28, true, 'k', $command), keyEvent($window, 0x28, false, 'k', $command));

    expect($engine->keyboard()->isDown(Key::K))->toBeFalse()
        ->and($engine->keyboard()->wasReleased(Key::K))->toBeTrue()
        ->and($engine->keyboard()->isDown(Key::LEFT_META))->toBeTrue()
        ->and($engine->keyboard()->text())->toBe('');
});

it('types no control characters and none of the function keys\' private-use characters', function (string $characters, int $flags, string $typed): void {
    expect(AppkitInputEngine::text($characters, $flags))->toBe($typed);
})->with([
    'a letter' => ['é', 0, 'é'],
    'option text' => ['∑', NSEventModifierFlags::OPTION->value, '∑'],
    'an arrow' => ["\u{F700}", NSEventModifierFlags::FUNCTION->value, ''],
    'return' => ["\r", 0, ''],
    'delete' => ["\x7F", 0, ''],
    'a shortcut' => ['c', NSEventModifierFlags::COMMAND->value, ''],
    'a control chord' => ["\x03", NSEventModifierFlags::CONTROL->value, ''],
]);

it('ignores key events for windows it does not hold', function (): void {
    inputWindow();
    $engine = appkitInput();
    $stray = NSEvent::keyEventWithTypeLocationModifierFlagsTimestampWindowNumberContextCharactersCharactersIgnoringModifiersIsARepeatKeyCode(
        NSEventType::KEY_DOWN, new NSPoint(0.0, 0.0), 0, 0.0, 999_999, null, 'q', 'q', false, 0x0C,
    );

    deliver($engine, $stray);

    expect($engine->keyboard()->downKeys())->toBe([]);
});

it('reads each modifier side from its flags-changed bits, and Caps Lock as one press', function (): void {
    $window = inputWindow();
    $engine = appkitInput();

    deliver($engine,
        flagsEvent($window, 0x3C, NSEventModifierFlags::SHIFT->value | 0x4),
        flagsEvent($window, 0x36, NSEventModifierFlags::SHIFT->value | 0x4 | NSEventModifierFlags::COMMAND->value | 0x10),
        flagsEvent($window, 0x39, NSEventModifierFlags::CAPS_LOCK->value | NSEventModifierFlags::SHIFT->value | 0x4 | NSEventModifierFlags::COMMAND->value | 0x10),
    );

    expect($engine->keyboard()->downKeys())->toBe([Key::RIGHT_SHIFT, Key::RIGHT_META])
        ->and($engine->keyboard()->isPressed(Key::CAPS_LOCK))->toBeTrue()
        ->and($engine->keyboard()->isDown(Key::CAPS_LOCK))->toBeFalse()
        ->and($engine->keyboard()->modifiers())->toEqual(new Modifiers(shift: true, meta: true));

    deliver($engine, flagsEvent($window, 0x3C, NSEventModifierFlags::COMMAND->value | 0x10));

    expect($engine->keyboard()->downKeys())->toBe([Key::RIGHT_META]);
});

it('clicks where it is clicked, in the window under the click', function (): void {
    $window = inputWindow('pointer');
    $engine = appkitInput();

    deliver($engine, clickEvent($window, NSEventType::RIGHT_MOUSE_DOWN, 40.0, 30.0));
    $mouse = $engine->mouse();

    expect([$mouse->x(), $mouse->y(), $mouse->window()])->toBe([40.0, 30.0, 'pointer'])
        ->and($mouse->isPressed(MouseButton::RIGHT))->toBeTrue();

    deliver($engine, clickEvent($window, NSEventType::RIGHT_MOUSE_UP, 40.0, 30.0), clickEvent($window, NSEventType::LEFT_MOUSE_DOWN, 50.0, 60.0));

    expect($mouse->wasReleased(MouseButton::RIGHT))->toBeTrue()
        ->and($mouse->isDown(MouseButton::LEFT))->toBeTrue();
});

it('reads other buttons 2, 3 and 4 as middle, x1 and x2', function (int $number, MouseButton $button): void {
    $window = inputWindow();
    $engine = appkitInput();

    // A Quartz event carries no window: the engine takes the window under its point.
    deliver($engine, otherButtonEvent(screenPoint($window, 20.0, 20.0), $number, true));

    expect($engine->mouse()->isDown($button))->toBeTrue();
})->with([
    [2, MouseButton::MIDDLE],
    [3, MouseButton::X1],
    [4, MouseButton::X2],
]);

it('names the window under the pointer though another window is key', function (): void {
    $key = inputWindow('key', 120, 160);
    $other = inputWindow('other', 520, 160);
    $key->native()->makeKeyWindow();
    pumpUntil(fn (): bool => $key->native()->isKeyWindow(), 3.0);
    $engine = appkitInput();

    deliver($engine, moveEvent(screenPoint($other, 70.0, 80.0), 2, 1));

    expect([$engine->mouse()->window(), $engine->mouse()->x(), $engine->mouse()->y()])->toBe(['other', 70.0, 80.0])
        ->and($engine->mouse()->motion())->toBe(['dx' => 2.0, 'dy' => 1.0])
        ->and($key->native()->isKeyWindow())->toBeTrue();
});

it('follows the pointer between events into a window that is not key, and out of every window', function (): void {
    $key = inputWindow('key', 120, 160);
    $other = inputWindow('other', 520, 160);
    $key->native()->makeKeyWindow();
    pumpUntil(fn (): bool => $key->native()->isKeyWindow(), 3.0);
    $at = screenPoint($other, 30.0, 40.0);
    $engine = appkitInput(function () use (&$at): NSPoint {
        return $at;
    });

    $engine->poll();
    $inside = [$engine->mouse()->window(), $engine->mouse()->x(), $engine->mouse()->y()];
    $at = new NSPoint(-20_000.0, -20_000.0);
    $engine->poll();

    expect($inside)->toBe(['other', 30.0, 40.0])
        ->and([$engine->mouse()->window(), $engine->mouse()->x(), $engine->mouse()->y()])->toBe([null, 30.0, 40.0]);
});

it('forgets the window the pointer left and keeps where it was', function (): void {
    $window = inputWindow('left');
    $engine = appkitInput();

    deliver($engine, moveEvent(screenPoint($window, 15.0, 25.0)), moveEvent(screenPoint($window, -200.0, -200.0)));

    expect([$engine->mouse()->x(), $engine->mouse()->y(), $engine->mouse()->window()])->toBe([15.0, 25.0, null]);
});

it('reads a line wheel as SDL does, and drops a scroll over no window of its own', function (): void {
    $window = inputWindow();
    $engine = appkitInput();

    deliver($engine, scrollEvent(screenPoint($window, 100.0, 100.0), CGScrollEventUnit::LINE, 3, -1), scrollEvent(screenPoint($window, -200.0, -200.0), CGScrollEventUnit::LINE, 5));

    expect($engine->mouse()->wheel())->toBe(['dx' => 1.0, 'dy' => 3.0]);
});

it('reads a trackpad\'s precise scroll in tenths', function (): void {
    $window = inputWindow();
    $engine = appkitInput();

    deliver($engine, scrollEvent(screenPoint($window, 100.0, 100.0), CGScrollEventUnit::PIXEL, 5));

    expect($engine->mouse()->wheel())->toBe(['dx' => 0.0, 'dy' => 0.5]);
});

it('turns a wheel the way SDL does on macOS', function (float $deltaX, float $deltaY, bool $precise, bool $inverted, array $read): void {
    expect(AppkitInputEngine::wheelDelta($deltaX, $deltaY, $precise, $inverted))->toBe($read);
})->with([
    'a mouse tick away' => [0.0, 0.4, false, false, [0.0, 1.0]],
    'a mouse tick toward' => [0.0, -1.2, false, false, [0.0, -2.0]],
    'a mouse tilt' => [-1.0, 0.0, false, false, [1.0, 0.0]],
    'a trackpad' => [0.25, 0.5, true, false, [-0.25, 0.5]],
    'natural scrolling' => [0.25, 0.5, true, true, [0.25, -0.5]],
    'a natural mouse' => [0.0, 0.4, false, true, [0.0, -1.0]],
]);

it('releases every key and button when the application resigns active', function (): void {
    $window = inputWindow();
    $engine = appkitInput();
    deliver($engine, keyEvent($window, 0x02, true, 'd'), clickEvent($window, NSEventType::LEFT_MOUSE_DOWN, 10.0, 10.0));

    NSNotificationCenter::defaultCenter()->postNotificationNameObject(NSApplicationDidResignActiveNotification, session()->application());
    deliver($engine, keyEvent($window, 0x02, false, 'd'));

    expect($engine->keyboard()->isDown(Key::D))->toBeFalse()
        ->and($engine->keyboard()->wasReleased(Key::D))->toBeTrue()
        ->and($engine->mouse()->isDown(MouseButton::LEFT))->toBeFalse();
});

it('turns moved events on for its windows, and off again at disconnect', function (): void {
    $window = inputWindow();
    $engine = appkitInput();

    $engine->poll();
    $on = $window->native()->acceptsMouseMovedEvents();
    $engine->disconnect();

    expect([$on, $window->native()->acceptsMouseMovedEvents()])->toBe([true, false]);
});

it('sees nothing once disconnected, and taps again when reconnected', function (): void {
    $window = inputWindow();
    $engine = appkitInput();
    $engine->disconnect();

    deliver($engine, keyEvent($window, 0x28, true, 'k'));

    expect($engine->connected())->toBeFalse()
        ->and($engine->keyboard()->downKeys())->toBe([]);

    $engine->connect();
    deliver($engine, keyEvent($window, 0x28, true, 'k'));

    expect($engine->keyboard()->downKeys())->toBe([Key::K]);
});

it('keeps edges until settled', function (): void {
    $window = inputWindow();
    $engine = appkitInput();

    deliver($engine, keyEvent($window, 0x26, true, 'j'), keyEvent($window, 0x26, false, 'j'), scrollEvent(screenPoint($window, 50.0, 50.0), CGScrollEventUnit::LINE, 1));
    $engine->poll();

    expect($engine->keyboard()->isPressed(Key::J))->toBeTrue()
        ->and($engine->keyboard()->wasReleased(Key::J))->toBeTrue();

    $engine->settle();

    expect($engine->keyboard()->isPressed(Key::J))->toBeFalse()
        ->and($engine->mouse()->wheel())->toBe(['dx' => 0.0, 'dy' => 0.0]);
});

/** The window point of ($x, $y) in $view, from its top left, once laid out. */
function inputControlPoint(Jovian\Toolkits\Appkit\Windows\AppkitWindow $window, NSView $view, float $x, float $y): NSPoint
{
    $window->present();
    pumpUntil(fn (): bool => $view->bounds()->width > 0.0, 3.0);
    $origin = $view->convertPointFromView(new NSPoint(0.0, 0.0), null);
    $sign = $view->convertPointFromView(new NSPoint(0.0, 1.0), null)->y - $origin->y;
    $ly = $view->isFlipped() ? $y : $view->bounds()->height - $y;

    return new NSPoint($x - $origin->x, ($ly - $origin->y) / $sign);
}

it('releases the mouse button a control\'s tracking loop kept the release of', function (): void {
    // NSButtonCell's trackMouse: dequeues the mouse-up itself: the session never pumps it.
    driver()->closeAll();
    $window = driver()->open('controls', 300, 200);
    $button = $window->column('c')->button('press', 'Press');
    $at = inputControlPoint($window, $button->native(), 10.0, 8.0);
    $engine = new AppkitInputEngine(inputFrame(), driver(), fn (): NSPoint => new NSPoint(-20_000.0, -20_000.0));
    $GLOBALS['appkit_input_engines'][] = $engine->connect();
    $number = $window->native()->windowNumber();
    $click = fn (NSEventType $type): NSEvent => NSEvent::mouseEventWithTypeLocationModifierFlagsTimestampWindowNumberContextEventNumberClickCountPressure(
        $type, $at, 0, 0.0, $number, null, 0, 1, 1.0,
    );

    deliver($engine, $click(NSEventType::LEFT_MOUSE_DOWN), $click(NSEventType::LEFT_MOUSE_UP));
    $engine->poll();

    expect($engine->mouse()->isDown(MouseButton::LEFT))->toBeFalse()
        ->and($engine->mouse()->wasReleased(MouseButton::LEFT))->toBeTrue();

    driver()->closeAll();
});

it('releases buttons and keys the system no longer holds, and the modifiers with them', function (): void {
    // A context menu's tracking keeps a right-button up, and any key up, from the session.
    $window = inputWindow();
    $buttons = 0b11111;
    $down = [0x28 => true, 0x38 => true];
    $engine = appkitInput(
        buttons: function () use (&$buttons): int {
            return $buttons;
        },
        keys: function (int $code) use (&$down): bool {
            return $down[$code] ?? false;
        },
    );
    deliver($engine,
        clickEvent($window, NSEventType::RIGHT_MOUSE_DOWN, 20.0, 20.0),
        flagsEvent($window, 0x38, NSEventModifierFlags::SHIFT->value | 0x2),
        keyEvent($window, 0x28, true, 'K', NSEventModifierFlags::SHIFT->value | 0x2),
    );
    $held = [$engine->mouse()->isDown(MouseButton::RIGHT), $engine->keyboard()->downKeys()];

    [$buttons, $down] = [0, []];
    $engine->poll();

    expect($held)->toBe([true, [Key::LEFT_SHIFT, Key::K]])
        ->and($engine->mouse()->isDown(MouseButton::RIGHT))->toBeFalse()
        ->and($engine->mouse()->wasReleased(MouseButton::RIGHT))->toBeTrue()
        ->and($engine->keyboard()->downKeys())->toBe([])
        ->and($engine->keyboard()->wasReleased(Key::K))->toBeTrue()
        ->and($engine->keyboard()->modifiers())->toEqual(new Modifiers());
});

it('releases a key and a modifier let go over another window of the app', function (): void {
    // The About panel or a save panel became key while they were held: their ups carry its number.
    $window = inputWindow();
    $engine = appkitInput();
    $shift = NSEventModifierFlags::SHIFT->value | 0x2;
    deliver($engine, flagsEvent($window, 0x38, $shift), keyEvent($window, 0x28, true, 'K', $shift));
    $up = fn (NSEventType $type, int $code, int $flags): NSEvent => NSEvent::keyEventWithTypeLocationModifierFlagsTimestampWindowNumberContextCharactersCharactersIgnoringModifiersIsARepeatKeyCode(
        $type, new NSPoint(0.0, 0.0), $flags, 0.0, 999_999, null, '', '', false, $code,
    );
    $press = NSEvent::keyEventWithTypeLocationModifierFlagsTimestampWindowNumberContextCharactersCharactersIgnoringModifiersIsARepeatKeyCode(
        NSEventType::KEY_DOWN, new NSPoint(0.0, 0.0), 0, 0.0, 999_999, null, 'q', 'q', false, 0x0C,
    );

    deliver($engine, $up(NSEventType::KEY_UP, 0x28, $shift), $up(NSEventType::FLAGS_CHANGED, 0x38, 0), $press);

    expect($engine->keyboard()->downKeys())->toBe([])
        ->and($engine->keyboard()->wasReleased(Key::K))->toBeTrue()
        ->and($engine->keyboard()->wasReleased(Key::LEFT_SHIFT))->toBeTrue()
        ->and($engine->keyboard()->modifiers())->toEqual(new Modifiers());
});
