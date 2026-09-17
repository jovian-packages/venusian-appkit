<?php

use Jovian\Bindings\AppKit\Enums\NSEventMask;
use Jovian\Bindings\AppKit\Enums\NSEventModifierFlags;
use Jovian\Bindings\AppKit\Enums\NSEventType;
use Jovian\Venusian\AppKit\Input\AppKitInputEngine;
use Surface\Contracts\HumanInput\GamepadAxis;
use Surface\Contracts\HumanInput\GamepadButton;
use Surface\Contracts\HumanInput\InputEngine;
use Surface\Contracts\HumanInput\Key;
use Surface\Contracts\HumanInput\MouseButton;
use Surface\HumanInput\Devices\GameController;
use Surface\HumanInput\Devices\GamePad;
use Venusian\AppKit\Tests\Support\FakeAppActivity;
use Venusian\AppKit\Tests\Support\FakeGamepadSource;
use Venusian\AppKit\Tests\Support\FakeInputTap;
use Venusian\AppKit\Tests\Support\FakeWindowSpace;

/**
 * One drainInput() record: every field present, unread ones zero/empty.
 *
 * @param array<string, mixed> $fields
 * @return array<string, mixed>
 */
function nsEvent(NSEventType $type, array $fields = []): array
{
    return array_merge([
        'type' => $type->value,
        'timestamp' => 1.0,
        'windowNumber' => 0,
        'keyCode' => 0,
        'characters' => '',
        'charactersIgnoringModifiers' => '',
        'isARepeat' => false,
        'modifierFlags' => 0,
        'buttonNumber' => 0,
        'clickCount' => 0,
        'locationInWindow' => ['x' => 0.0, 'y' => 0.0],
        'deltaX' => 0.0,
        'deltaY' => 0.0,
        'scrollingDeltaX' => 0.0,
        'scrollingDeltaY' => 0.0,
        'hasPreciseScrollingDeltas' => false,
        'isDirectionInvertedFromDevice' => false,
    ], $fields);
}

/** @return array{AppKitInputEngine, FakeInputTap, FakeGamepadSource, FakeWindowSpace, FakeAppActivity} connected */
function appkitEngine(?FakeGamepadSource $source = null): array
{
    $tap = new FakeInputTap();
    $source ??= new FakeGamepadSource();
    $windows = new FakeWindowSpace([42 => ['name' => 'main', 'content_width' => 800.0, 'content_height' => 600.0]]);
    $activity = new FakeAppActivity();
    $engine = new AppKitInputEngine($tap, $source, $windows, $activity);
    $engine->connect();

    return [$engine, $tap, $source, $windows, $activity];
}

/** @return array<string, bool> every extended-profile button, up */
function extendedButtons(): array
{
    $buttons = [];
    foreach (GamepadButton::cases() as $button) {
        $buttons[$button->value] = false;
    }

    return $buttons;
}

it('is the appkit engine, with no devices until connected', function () {
    $engine = new AppKitInputEngine(new FakeInputTap(), new FakeGamepadSource(), new FakeWindowSpace(), new FakeAppActivity());

    expect($engine->engine())->toBe(InputEngine::APPKIT)
        ->and($engine->connected())->toBeFalse()
        ->and($engine->keyboard())->toBeNull()
        ->and($engine->mouse())->toBeNull()
        ->and($engine->gamePads())->toBe([])
        ->and($engine->gameControllers())->toBe([]);
});

it('watches keys, flags, mouse buttons, drags, moves and the wheel on connect', function () {
    [$engine, $tap, $source, , $activity] = appkitEngine();

    $mask = NSEventMask::LEFT_MOUSE_DOWN->value | NSEventMask::LEFT_MOUSE_UP->value
        | NSEventMask::RIGHT_MOUSE_DOWN->value | NSEventMask::RIGHT_MOUSE_UP->value
        | NSEventMask::MOUSE_MOVED->value | NSEventMask::LEFT_MOUSE_DRAGGED->value
        | NSEventMask::RIGHT_MOUSE_DRAGGED->value | NSEventMask::KEY_DOWN->value
        | NSEventMask::KEY_UP->value | NSEventMask::FLAGS_CHANGED->value
        | NSEventMask::SCROLL_WHEEL->value | NSEventMask::OTHER_MOUSE_DOWN->value
        | NSEventMask::OTHER_MOUSE_UP->value | NSEventMask::OTHER_MOUSE_DRAGGED->value;

    expect($engine->connected())->toBeTrue()
        ->and($tap->masks)->toBe([$mask])
        ->and($mask)->toBe((1 << 1) | (1 << 2) | (1 << 3) | (1 << 4) | (1 << 5) | (1 << 6) | (1 << 7) | (1 << 10) | (1 << 11) | (1 << 12) | (1 << 22) | (1 << 25) | (1 << 26) | (1 << 27))
        ->and($source->changed)->toBeInstanceOf(Closure::class)
        ->and($activity->resigned)->toBeInstanceOf(Closure::class)
        ->and($engine->keyboard())->not->toBeNull()
        ->and($engine->mouse())->not->toBeNull();
});

it('presses a key once, and its auto-repeats still type', function () {
    [$engine, $tap] = appkitEngine();

    $engine->apply([
        nsEvent(NSEventType::KEY_DOWN, ['keyCode' => 0x00, 'characters' => 'a']),
        nsEvent(NSEventType::KEY_DOWN, ['keyCode' => 0x00, 'characters' => 'a', 'isARepeat' => true]),
        nsEvent(NSEventType::KEY_DOWN, ['keyCode' => 0x00, 'characters' => 'a', 'isARepeat' => true]),
    ]);

    expect($engine->keyboard()->pressedKeys())->toBe([Key::A])
        ->and($engine->keyboard()->isDown(Key::A))->toBeTrue()
        ->and($engine->keyboard()->text())->toBe('aaa');

    $tap->queue = [nsEvent(NSEventType::KEY_DOWN, ['keyCode' => 0x00, 'characters' => 'a', 'isARepeat' => true])];
    $engine->poll();

    expect($engine->keyboard()->isPressed(Key::A))->toBeFalse()
        ->and($engine->keyboard()->isDown(Key::A))->toBeTrue()
        ->and($engine->keyboard()->text())->toBe('a');

    $engine->apply([nsEvent(NSEventType::KEY_UP, ['keyCode' => 0x00])]);

    expect($engine->keyboard()->isDown(Key::A))->toBeFalse()
        ->and($engine->keyboard()->wasReleased(Key::A))->toBeTrue();
});

it('lets Option-composed characters into text', function () {
    [$engine] = appkitEngine();
    $option = NSEventModifierFlags::NS_EVENT_MODIFIER_FLAG_OPTION->value;

    $engine->apply([nsEvent(NSEventType::KEY_DOWN, ['keyCode' => 0x1F, 'characters' => 'ø', 'charactersIgnoringModifiers' => 'o', 'modifierFlags' => $option | 0x0020])]);

    expect($engine->keyboard()->text())->toBe('ø')
        ->and($engine->keyboard()->isDown(Key::O))->toBeTrue()
        ->and($engine->keyboard()->modifiers()->alt)->toBeTrue();
});

it('withholds text while Command or Control is held, but still presses the key', function () {
    [$engine] = appkitEngine();
    $command = NSEventModifierFlags::NS_EVENT_MODIFIER_FLAG_COMMAND->value;
    $control = NSEventModifierFlags::NS_EVENT_MODIFIER_FLAG_CONTROL->value;

    $engine->apply([
        nsEvent(NSEventType::KEY_DOWN, ['keyCode' => 0x08, 'characters' => 'c', 'modifierFlags' => $command]),
        nsEvent(NSEventType::KEY_DOWN, ['keyCode' => 0x00, 'characters' => "\x01", 'modifierFlags' => $control]),
    ]);

    expect($engine->keyboard()->text())->toBe('')
        ->and($engine->keyboard()->isDown(Key::C))->toBeTrue()
        ->and($engine->keyboard()->isDown(Key::A))->toBeTrue()
        ->and($engine->keyboard()->modifiers()->ctrl)->toBeTrue()
        ->and($engine->keyboard()->modifiers()->meta)->toBeFalse();
});

it('never lets control or function-key characters into text', function () {
    [$engine] = appkitEngine();
    $shift = NSEventModifierFlags::NS_EVENT_MODIFIER_FLAG_SHIFT->value;

    $engine->apply([
        nsEvent(NSEventType::KEY_DOWN, ['keyCode' => 0x24, 'characters' => "\r"]),
        nsEvent(NSEventType::KEY_DOWN, ['keyCode' => 0x33, 'characters' => "\x7F"]),
        nsEvent(NSEventType::KEY_DOWN, ['keyCode' => 0x7E, 'characters' => "\u{F700}"]),
        nsEvent(NSEventType::KEY_DOWN, ['keyCode' => 0x7A, 'characters' => "\u{F704}"]),
        nsEvent(NSEventType::KEY_DOWN, ['keyCode' => 0x00, 'characters' => 'A', 'modifierFlags' => $shift]),
        nsEvent(NSEventType::KEY_DOWN, ['keyCode' => 0x0E, 'characters' => 'é']),
    ]);

    expect($engine->keyboard()->text())->toBe('Aé')
        ->and($engine->keyboard()->isDown(Key::ENTER))->toBeTrue()
        ->and($engine->keyboard()->isDown(Key::UP))->toBeTrue();
});

it('reads left shift down then up from its device bit on FLAGS_CHANGED', function () {
    [$engine] = appkitEngine();
    $shift = NSEventModifierFlags::NS_EVENT_MODIFIER_FLAG_SHIFT->value;

    $engine->apply([nsEvent(NSEventType::FLAGS_CHANGED, ['keyCode' => 0x38, 'modifierFlags' => $shift | 0x0002])]);

    expect($engine->keyboard()->isPressed(Key::LEFT_SHIFT))->toBeTrue()
        ->and($engine->keyboard()->isDown(Key::RIGHT_SHIFT))->toBeFalse()
        ->and($engine->keyboard()->modifiers()->shift)->toBeTrue();

    $engine->apply([nsEvent(NSEventType::FLAGS_CHANGED, ['keyCode' => 0x38, 'modifierFlags' => 0])]);

    expect($engine->keyboard()->isDown(Key::LEFT_SHIFT))->toBeFalse()
        ->and($engine->keyboard()->wasReleased(Key::LEFT_SHIFT))->toBeTrue()
        ->and($engine->keyboard()->modifiers()->shift)->toBeFalse();
});

it('keeps left shift down when right shift is released while both are held', function () {
    [$engine] = appkitEngine();
    $shift = NSEventModifierFlags::NS_EVENT_MODIFIER_FLAG_SHIFT->value;

    $engine->apply([
        nsEvent(NSEventType::FLAGS_CHANGED, ['keyCode' => 0x38, 'modifierFlags' => $shift | 0x0002]),
        nsEvent(NSEventType::FLAGS_CHANGED, ['keyCode' => 0x3C, 'modifierFlags' => $shift | 0x0002 | 0x0004]),
        nsEvent(NSEventType::FLAGS_CHANGED, ['keyCode' => 0x3C, 'modifierFlags' => $shift | 0x0002]),
    ]);

    expect($engine->keyboard()->isDown(Key::LEFT_SHIFT))->toBeTrue()
        ->and($engine->keyboard()->isDown(Key::RIGHT_SHIFT))->toBeFalse()
        ->and($engine->keyboard()->wasReleased(Key::RIGHT_SHIFT))->toBeTrue();
});

it('tells right control from left control by device bit', function () {
    [$engine] = appkitEngine();
    $control = NSEventModifierFlags::NS_EVENT_MODIFIER_FLAG_CONTROL->value;

    $engine->apply([nsEvent(NSEventType::FLAGS_CHANGED, ['keyCode' => 0x3E, 'modifierFlags' => $control | 0x2000])]);

    expect($engine->keyboard()->downKeys())->toBe([Key::RIGHT_CTRL])
        ->and($engine->keyboard()->modifiers()->ctrl)->toBeTrue();

    $engine->apply([nsEvent(NSEventType::FLAGS_CHANGED, ['keyCode' => 0x3B, 'modifierFlags' => $control | 0x2000 | 0x0001])]);

    expect($engine->keyboard()->isDown(Key::LEFT_CTRL))->toBeTrue()
        ->and($engine->keyboard()->isDown(Key::RIGHT_CTRL))->toBeTrue();
});

it('maps command, option and caps lock on FLAGS_CHANGED', function () {
    [$engine] = appkitEngine();
    $command = NSEventModifierFlags::NS_EVENT_MODIFIER_FLAG_COMMAND->value;
    $option = NSEventModifierFlags::NS_EVENT_MODIFIER_FLAG_OPTION->value;
    $caps = NSEventModifierFlags::NS_EVENT_MODIFIER_FLAG_CAPS_LOCK->value;

    $engine->apply([
        nsEvent(NSEventType::FLAGS_CHANGED, ['keyCode' => 0x36, 'modifierFlags' => $command | 0x0010]),
        nsEvent(NSEventType::FLAGS_CHANGED, ['keyCode' => 0x3A, 'modifierFlags' => $command | 0x0010 | $option | 0x0020]),
        nsEvent(NSEventType::FLAGS_CHANGED, ['keyCode' => 0x39, 'modifierFlags' => $command | 0x0010 | $option | 0x0020 | $caps]),
    ]);

    expect($engine->keyboard()->isDown(Key::RIGHT_META))->toBeTrue()
        ->and($engine->keyboard()->isDown(Key::LEFT_META))->toBeFalse()
        ->and($engine->keyboard()->isDown(Key::LEFT_ALT))->toBeTrue()
        ->and($engine->keyboard()->isDown(Key::CAPS_LOCK))->toBeTrue()
        ->and($engine->keyboard()->modifiers()->meta)->toBeTrue()
        ->and($engine->keyboard()->modifiers()->alt)->toBeTrue()
        ->and($engine->keyboard()->modifiers()->shift)->toBeFalse();
});

it('releases a held sided modifier once an event shows its device bit clear', function () {
    [$engine] = appkitEngine();
    $command = NSEventModifierFlags::NS_EVENT_MODIFIER_FLAG_COMMAND->value;
    $caps = NSEventModifierFlags::NS_EVENT_MODIFIER_FLAG_CAPS_LOCK->value;

    $engine->apply([
        nsEvent(NSEventType::FLAGS_CHANGED, ['keyCode' => 0x37, 'modifierFlags' => $command | 0x0008]),
        nsEvent(NSEventType::FLAGS_CHANGED, ['keyCode' => 0x39, 'modifierFlags' => $command | 0x0008 | $caps]),
    ]);

    expect($engine->keyboard()->isDown(Key::LEFT_META))->toBeTrue()
        ->and($engine->keyboard()->isDown(Key::CAPS_LOCK))->toBeTrue();

    $engine->apply([nsEvent(NSEventType::KEY_DOWN, ['keyCode' => 0x00, 'characters' => 'a', 'modifierFlags' => 0])]);

    expect($engine->keyboard()->isDown(Key::LEFT_META))->toBeFalse()
        ->and($engine->keyboard()->wasReleased(Key::LEFT_META))->toBeTrue()
        ->and($engine->keyboard()->isDown(Key::CAPS_LOCK))->toBeTrue()
        ->and($engine->keyboard()->modifiers()->meta)->toBeFalse()
        ->and($engine->keyboard()->text())->toBe('a');
});

it('releases stale modifiers on KEY_UP and FLAGS_CHANGED too, never pressing one', function () {
    [$engine] = appkitEngine();
    $shift = NSEventModifierFlags::NS_EVENT_MODIFIER_FLAG_SHIFT->value;
    $control = NSEventModifierFlags::NS_EVENT_MODIFIER_FLAG_CONTROL->value;

    $engine->apply([
        nsEvent(NSEventType::FLAGS_CHANGED, ['keyCode' => 0x38, 'modifierFlags' => $shift | 0x0002]),
        nsEvent(NSEventType::KEY_UP, ['keyCode' => 0x00, 'modifierFlags' => 0x0004]),
    ]);

    expect($engine->keyboard()->isDown(Key::LEFT_SHIFT))->toBeFalse()
        ->and($engine->keyboard()->isDown(Key::RIGHT_SHIFT))->toBeFalse();

    $engine->apply([
        nsEvent(NSEventType::FLAGS_CHANGED, ['keyCode' => 0x3A, 'modifierFlags' => 0x0020]),
        nsEvent(NSEventType::FLAGS_CHANGED, ['keyCode' => 0x3B, 'modifierFlags' => $control | 0x0001]),
    ]);

    expect($engine->keyboard()->isDown(Key::LEFT_ALT))->toBeFalse()
        ->and($engine->keyboard()->wasReleased(Key::LEFT_ALT))->toBeTrue()
        ->and($engine->keyboard()->isDown(Key::LEFT_CTRL))->toBeTrue();
});

it('releases every key and mouse button on the poll after the app resigns active', function () {
    [$engine, $tap, , , $activity] = appkitEngine();
    $shift = NSEventModifierFlags::NS_EVENT_MODIFIER_FLAG_SHIFT->value;
    $tap->queue = [
        nsEvent(NSEventType::FLAGS_CHANGED, ['keyCode' => 0x38, 'modifierFlags' => $shift | 0x0002]),
        nsEvent(NSEventType::KEY_DOWN, ['keyCode' => 0x0D, 'characters' => 'W', 'modifierFlags' => $shift | 0x0002]),
        nsEvent(NSEventType::LEFT_MOUSE_DOWN, ['windowNumber' => 42, 'buttonNumber' => 0]),
        nsEvent(NSEventType::RIGHT_MOUSE_DOWN, ['windowNumber' => 42, 'buttonNumber' => 1]),
    ];
    $engine->poll();

    $activity->resign();

    expect($engine->keyboard()->isDown(Key::W))->toBeTrue()
        ->and($engine->mouse()->isDown(MouseButton::LEFT))->toBeTrue();

    $engine->poll();

    expect($engine->keyboard()->downKeys())->toBe([])
        ->and($engine->keyboard()->releasedKeys())->toBe([Key::LEFT_SHIFT, Key::W])
        ->and($engine->keyboard()->modifiers()->shift)->toBeFalse()
        ->and($engine->mouse()->isDown(MouseButton::LEFT))->toBeFalse()
        ->and($engine->mouse()->wasReleased(MouseButton::LEFT))->toBeTrue()
        ->and($engine->mouse()->wasReleased(MouseButton::RIGHT))->toBeTrue()
        ->and($engine->mouse()->wasReleased(MouseButton::MIDDLE))->toBeFalse();

    $tap->queue = [nsEvent(NSEventType::KEY_DOWN, ['keyCode' => 0x0D, 'characters' => 'w'])];
    $engine->poll();

    expect($engine->keyboard()->releasedKeys())->toBe([])
        ->and($engine->keyboard()->isPressed(Key::W))->toBeTrue();
});

it('flips mouse y against the content height and names the window', function () {
    [$engine] = appkitEngine();

    $engine->apply([nsEvent(NSEventType::MOUSE_MOVED, [
        'windowNumber' => 42,
        'locationInWindow' => ['x' => 30.0, 'y' => 100.0],
        'deltaX' => 3.0,
        'deltaY' => -2.0,
    ])]);

    expect($engine->mouse()->x())->toBe(30.0)
        ->and($engine->mouse()->y())->toBe(500.0)
        ->and($engine->mouse()->window())->toBe('main')
        ->and($engine->mouse()->motion())->toBe(['dx' => 3.0, 'dy' => -2.0]);
});

it('leaves the position alone for a window it does not know, but keeps motion and buttons', function () {
    [$engine] = appkitEngine();

    $engine->apply([
        nsEvent(NSEventType::MOUSE_MOVED, ['windowNumber' => 42, 'locationInWindow' => ['x' => 10.0, 'y' => 590.0]]),
        nsEvent(NSEventType::LEFT_MOUSE_DOWN, ['windowNumber' => 7, 'locationInWindow' => ['x' => 400.0, 'y' => 1.0], 'deltaX' => 1.5]),
    ]);

    expect($engine->mouse()->x())->toBe(10.0)
        ->and($engine->mouse()->y())->toBe(10.0)
        ->and($engine->mouse()->window())->toBe('main')
        ->and($engine->mouse()->motion()['dx'])->toBe(1.5)
        ->and($engine->mouse()->isPressed(MouseButton::LEFT))->toBeTrue();
});

it('maps button numbers to mouse buttons, down on *_DOWN and up on *_UP', function () {
    [$engine] = appkitEngine();

    $engine->apply([
        nsEvent(NSEventType::LEFT_MOUSE_DOWN, ['windowNumber' => 42, 'buttonNumber' => 0]),
        nsEvent(NSEventType::RIGHT_MOUSE_DOWN, ['windowNumber' => 42, 'buttonNumber' => 1]),
        nsEvent(NSEventType::OTHER_MOUSE_DOWN, ['windowNumber' => 42, 'buttonNumber' => 2]),
        nsEvent(NSEventType::OTHER_MOUSE_DOWN, ['windowNumber' => 42, 'buttonNumber' => 3]),
        nsEvent(NSEventType::OTHER_MOUSE_DOWN, ['windowNumber' => 42, 'buttonNumber' => 4]),
        nsEvent(NSEventType::OTHER_MOUSE_DOWN, ['windowNumber' => 42, 'buttonNumber' => 9]),
    ]);

    foreach (MouseButton::cases() as $button) {
        expect($engine->mouse()->isDown($button))->toBeTrue();
    }

    $engine->apply([
        nsEvent(NSEventType::RIGHT_MOUSE_UP, ['windowNumber' => 42, 'buttonNumber' => 1]),
        nsEvent(NSEventType::OTHER_MOUSE_UP, ['windowNumber' => 42, 'buttonNumber' => 4]),
        nsEvent(NSEventType::LEFT_MOUSE_DRAGGED, ['windowNumber' => 42, 'buttonNumber' => 0]),
    ]);

    expect($engine->mouse()->isDown(MouseButton::LEFT))->toBeTrue()
        ->and($engine->mouse()->isDown(MouseButton::RIGHT))->toBeFalse()
        ->and($engine->mouse()->wasReleased(MouseButton::RIGHT))->toBeTrue()
        ->and($engine->mouse()->isDown(MouseButton::X2))->toBeFalse()
        ->and($engine->mouse()->isDown(MouseButton::X1))->toBeTrue();
});

it('names no window for a point outside the content, but still moves', function () {
    [$engine] = appkitEngine();

    $engine->apply([nsEvent(NSEventType::LEFT_MOUSE_DRAGGED, ['windowNumber' => 42, 'locationInWindow' => ['x' => 810.0, 'y' => 100.0]])]);

    expect($engine->mouse()->x())->toBe(810.0)
        ->and($engine->mouse()->y())->toBe(500.0)
        ->and($engine->mouse()->window())->toBeNull();

    $engine->apply([nsEvent(NSEventType::LEFT_MOUSE_DRAGGED, ['windowNumber' => 42, 'locationInWindow' => ['x' => 10.0, 'y' => 650.0]])]);

    expect($engine->mouse()->y())->toBe(-50.0)
        ->and($engine->mouse()->window())->toBeNull();

    $engine->apply([nsEvent(NSEventType::LEFT_MOUSE_DRAGGED, ['windowNumber' => 42, 'locationInWindow' => ['x' => 800.0, 'y' => 1.0]])]);

    expect($engine->mouse()->window())->toBeNull();

    $engine->apply([nsEvent(NSEventType::MOUSE_MOVED, ['windowNumber' => 42, 'locationInWindow' => ['x' => 0.0, 'y' => 600.0]])]);

    expect($engine->mouse()->x())->toBe(0.0)
        ->and($engine->mouse()->y())->toBe(0.0)
        ->and($engine->mouse()->window())->toBe('main');
});

it('scales precise scrolling deltas from points to lines', function () {
    [$engine] = appkitEngine();

    $engine->apply([
        nsEvent(NSEventType::SCROLL_WHEEL, ['scrollingDeltaX' => 20.0, 'scrollingDeltaY' => -40.0, 'hasPreciseScrollingDeltas' => true]),
        nsEvent(NSEventType::SCROLL_WHEEL, ['scrollingDeltaX' => 0.0, 'scrollingDeltaY' => 1.0, 'hasPreciseScrollingDeltas' => false]),
    ]);

    expect($engine->mouse()->wheel())->toBe(['dx' => 2.0, 'dy' => -3.0]);
});

it('reads the wheel physically, flipping natural-scrolling deltas', function () {
    [$engine] = appkitEngine();

    $engine->apply([nsEvent(NSEventType::SCROLL_WHEEL, ['scrollingDeltaX' => -1.0, 'scrollingDeltaY' => 2.0, 'isDirectionInvertedFromDevice' => false])]);

    expect($engine->mouse()->wheel())->toBe(['dx' => -1.0, 'dy' => 2.0]);

    $engine->mouse()->settle();
    $engine->apply([nsEvent(NSEventType::SCROLL_WHEEL, ['scrollingDeltaX' => -10.0, 'scrollingDeltaY' => 20.0, 'hasPreciseScrollingDeltas' => true, 'isDirectionInvertedFromDevice' => true])]);

    expect($engine->mouse()->wheel())->toBe(['dx' => 1.0, 'dy' => -2.0]);
});

it('settles the devices each poll, asks for mouse moves, and applies the drained records', function () {
    [$engine, $tap, , $windows] = appkitEngine();
    $tap->queue = [nsEvent(NSEventType::KEY_DOWN, ['keyCode' => 0x31, 'characters' => ' '])];

    $engine->poll();

    expect($engine->keyboard()->isPressed(Key::SPACE))->toBeTrue()
        ->and($engine->keyboard()->text())->toBe(' ')
        ->and($windows->accept_calls)->toBe(1);

    $engine->poll();

    expect($engine->keyboard()->isPressed(Key::SPACE))->toBeFalse()
        ->and($engine->keyboard()->isDown(Key::SPACE))->toBeTrue()
        ->and($engine->keyboard()->text())->toBe('')
        ->and($windows->accept_calls)->toBe(2);
});

it('builds an extended pad that appears via onChange as a gc-<handle> controller, sticks as the source reports them', function () {
    [$engine, , $source] = appkitEngine();

    $buttons = extendedButtons();
    $buttons[GamepadButton::SOUTH->value] = true;
    $source->plug(7, 'Xbox Wireless Controller', true, $buttons, [
        GamepadAxis::LEFT_X->value => 0.25,
        GamepadAxis::LEFT_Y->value => -0.5,
        GamepadAxis::RIGHT_X->value => 0.0,
        GamepadAxis::RIGHT_Y->value => 0.75,
        GamepadAxis::LEFT_TRIGGER->value => 1.0,
        GamepadAxis::RIGHT_TRIGGER->value => 0.0,
    ]);

    expect($engine->gameControllers())->toBe([]);

    $engine->poll();
    $pad = $engine->gameControllers()['gc-7'] ?? null;

    expect($pad)->toBeInstanceOf(GameController::class)
        ->and($pad->name())->toBe('Xbox Wireless Controller')
        ->and($pad->axes())->toBe(GamepadAxis::cases())
        ->and($pad->isPressed(GamepadButton::SOUTH))->toBeTrue()
        ->and($pad->isDown(GamepadButton::EAST))->toBeFalse()
        ->and($pad->leftStick())->toBe(['x' => 0.25, 'y' => -0.5])
        ->and($pad->rightStick())->toBe(['x' => 0.0, 'y' => 0.75])
        ->and($pad->leftTrigger())->toBe(1.0)
        ->and($engine->gamePads())->toBe([]);

    $source->unplug(7);
    $engine->poll();

    expect($engine->gameControllers())->toBe([]);
});

it('builds a micro-profile pad as a GamePad with only the buttons it has', function () {
    $source = new FakeGamepadSource();
    $source->pads[3] = [
        'name' => 'Siri Remote',
        'extended' => false,
        'buttons' => [
            GamepadButton::SOUTH->value => false,
            GamepadButton::WEST->value => true,
            GamepadButton::START->value => false,
            GamepadButton::DPAD_UP->value => false,
            GamepadButton::DPAD_DOWN->value => false,
            GamepadButton::DPAD_LEFT->value => false,
            GamepadButton::DPAD_RIGHT->value => false,
        ],
        'axes' => [],
    ];

    [$engine] = appkitEngine($source);
    $engine->poll();
    $pad = $engine->gamePads()['gc-3'] ?? null;

    expect($pad)->toBeInstanceOf(GamePad::class)
        ->and($pad)->not->toBeInstanceOf(GameController::class)
        ->and($pad->supports(GamepadButton::SOUTH))->toBeTrue()
        ->and($pad->supports(GamepadButton::EAST))->toBeFalse()
        ->and($pad->pressedButtons())->toBe([GamepadButton::WEST])
        ->and($engine->gameControllers())->toBe([]);
});

it('stops the tap and the gamepad source on disconnect and drops every device', function () {
    [$engine, $tap, $source, , $activity] = appkitEngine();
    $source->plug(7, 'Pad', true, extendedButtons(), []);
    $engine->poll();

    $engine->disconnect();

    expect($tap->masks[array_key_last($tap->masks)])->toBe(0)
        ->and($source->stopped)->toBeTrue()
        ->and($activity->stopped)->toBeTrue()
        ->and($engine->connected())->toBeFalse()
        ->and($engine->keyboard())->toBeNull()
        ->and($engine->mouse())->toBeNull()
        ->and($engine->gameControllers())->toBe([]);
});

it('does nothing on poll or apply while disconnected', function () {
    $tap = new FakeInputTap();
    $windows = new FakeWindowSpace();
    $engine = new AppKitInputEngine($tap, new FakeGamepadSource(), $windows, new FakeAppActivity());
    $tap->queue = [nsEvent(NSEventType::KEY_DOWN, ['keyCode' => 0x00])];

    $engine->poll();
    $engine->apply([nsEvent(NSEventType::KEY_DOWN, ['keyCode' => 0x00])]);

    expect($engine->keyboard())->toBeNull()
        ->and($tap->masks)->toBe([])
        ->and($tap->queue)->toHaveCount(1)
        ->and($windows->accept_calls)->toBe(0);
});

it('hands the tap its window numbers so unclaimed keys do not beep, only when they change', function () {
    [$engine, $tap, , $windows] = appkitEngine();

    $engine->poll();
    $engine->poll();
    $windows->windows[7] = ['name' => 'second', 'content_width' => 100.0, 'content_height' => 100.0];
    $engine->poll();
    $engine->disconnect();
    $engine->connect();
    $engine->poll();

    expect($tap->swallowed)->toBe([[42], [7, 42], [7, 42]]);
});
