<?php

declare(strict_types=1);

require_once __DIR__.'/Support.php';

use Jovian\Toolkits\Appkit\Input\AppkitInputBackend;
use Jovian\Toolkits\Appkit\Input\AppkitInputChannel;
use Jovian\Toolkits\Appkit\Input\AppleMouse;
use Jovian\Toolkits\Appkit\Input\GesturePhase;
use Jovian\Toolkits\Appkit\Input\Mail\ForceChanged;
use Jovian\Toolkits\Appkit\Input\Mail\Pinched;
use Jovian\Toolkits\Appkit\Input\Mail\Rotated;
use Surface\Contracts\Graphics\ActorStage;
use Surface\Contracts\HumanInput\HumanInputException;
use Surface\Contracts\HumanInput\InputContext;
use Surface\Contracts\HumanInput\Key;
use Surface\Contracts\HumanInput\Mail\KeyChanged;
use Surface\Contracts\HumanInput\Mail\PointerButtonChanged;
use Surface\Contracts\HumanInput\Mail\PointerMoved;
use Surface\Contracts\HumanInput\Mail\PointerScrolled;
use Surface\Contracts\HumanInput\Mail\TextTyped;
use Surface\Contracts\HumanInput\Modifiers;
use Surface\Contracts\HumanInput\MouseButton;
use Surface\Contracts\HumanInput\ScrollPhase;
use Surface\HumanInput\HumanInput;

afterEach(function (): void {
    hiCloseWindows();
});

/* The backend and its place in the application. */

it('is the backend the application booted with, from this package\'s provider, with the gamecontroller pad source', function (): void {
    expect(HumanInput::manager()->backend())->toBeInstanceOf(AppkitInputBackend::class)
        ->and(HumanInput::manager()->backends())->toContain('appkit')
        ->and(HumanInput::manager()->padSources())->toContain('gamecontroller');
});

it('serves AppKit windows on macOS, whatever engine draws their canvases', function (string $toolkit, string $engine, string $os, bool $serves): void {
    expect((new AppkitInputBackend())->serves(new InputContext($toolkit, $engine, $os)))->toBe($serves);
})->with([
    'appkit, velvet' => ['appkit', 'velvet', 'mac', true],
    'appkit, metal' => ['appkit', 'metal', 'mac', true],
    'raven' => ['raven', 'velvet', 'mac', false],
    'gtk' => ['gtk', 'velvet', 'mac', false],
    'appkit off macOS' => ['appkit', 'velvet', 'linux', false],
]);

it('opens an AppKit canvas\'s input as an AppKit channel with an Apple pointer', function (): void {
    hiInput();
    [, $top] = hiWindow();

    expect($top->input())->toBeInstanceOf(AppkitInputChannel::class)
        ->and($top->input()->mouse())->toBeInstanceOf(AppleMouse::class)
        ->and($top->keyboardInput())->toBe($top->input());
});

it('refuses a stage that is not an AppKit canvas', function (): void {
    $stage = new class implements ActorStage {
        public function pixelSize(): array { return [1, 1]; }
        public function pixelScale(): float { return 1.0; }
        public function display(string $rgba8, int $width, int $height): void {}
        public function nativeSurface(): ?object { return null; }
    };

    expect(fn () => hiInput()->channel($stage))->toThrow(HumanInputException::class, 'reaches AppKit canvases only');
});

/* The pointer. */

it('puts a click where it lands on the canvas, in its pixels from its top left', function (): void {
    $input = hiInput();
    [$window, $top] = hiWindow();
    $channel = $top->input();
    HiSystem::$buttons = 1;

    hiDeliver($input, hiMouse($window, NSEventType::LEFT_MOUSE_DOWN, 10, 20));

    $scale = hiScale($window);
    expect([$channel->mouse()->x(), $channel->mouse()->y(), $channel->mouse()->inside()])->toBe([10 * $scale, 20 * $scale, true])
        ->and($channel->mouse()->isPressed(MouseButton::LEFT))->toBeTrue()
        ->and(hiMailSent())->toBe([PointerMoved::class, PointerButtonChanged::class]);
});

it('measures from each canvas\'s own top left', function (): void {
    $input = hiInput();
    [$window, , $bottom] = hiWindow();
    $channel = $bottom->input();

    // the bottom canvas starts 100 points down the window
    hiDeliver($input, hiMouse($window, NSEventType::MOUSE_MOVED, 30, 140));

    $scale = hiScale($window);
    expect([$channel->mouse()->x(), $channel->mouse()->y()])->toBe([30 * $scale, 40 * $scale]);
});

it('follows the pointer from one canvas to the next, telling the one it left that it is outside', function (): void {
    $input = hiInput();
    [$window, $top, $bottom] = hiWindow();
    [$upper, $lower] = [$top->input(), $bottom->input()];

    hiDeliver($input, hiMouse($window, NSEventType::MOUSE_MOVED, 50, 50), hiMouse($window, NSEventType::MOUSE_MOVED, 60, 70));
    $scale = hiScale($window);
    $over = [$upper->mouse()->inside(), $upper->mouse()->motion(), $lower->mouse()->inside()];

    hiDeliver($input, hiMouse($window, NSEventType::MOUSE_MOVED, 60, 150));

    expect($over)->toBe([true, ['dx' => 10 * $scale, 'dy' => 20 * $scale], false])
        ->and($upper->mouse()->inside())->toBeFalse()
        ->and($lower->mouse()->inside())->toBeTrue()
        ->and([$lower->mouse()->x(), $lower->mouse()->y()])->toBe([60 * $scale, 50 * $scale]);
});

it('keeps a button with the canvas it was pressed on: its drags and its release, wherever the pointer goes', function (): void {
    $input = hiInput();
    [$window, $top, $bottom] = hiWindow();
    [$upper, $lower] = [$top->input(), $bottom->input()];
    HiSystem::$buttons = 1;

    hiDeliver($input,
        hiMouse($window, NSEventType::LEFT_MOUSE_DOWN, 20, 20),
        hiMouse($window, NSEventType::LEFT_MOUSE_DRAGGED, 20, 160),
    );
    $dragging = [$upper->mouse()->isDown(MouseButton::LEFT), $upper->mouse()->inside(), $upper->mouse()->y()];
    HiSystem::$buttons = 0;
    hiDeliver($input, hiMouse($window, NSEventType::LEFT_MOUSE_UP, 20, 160));

    $scale = hiScale($window);
    expect($dragging)->toBe([true, false, 160 * $scale])
        ->and($upper->mouse()->isDown(MouseButton::LEFT))->toBeFalse()
        ->and($upper->mouse()->wasReleased(MouseButton::LEFT))->toBeTrue()
        ->and($lower->mouse()->isPressed(MouseButton::LEFT))->toBeFalse()
        ->and($lower->mouse()->wasReleased(MouseButton::LEFT))->toBeFalse()
        ->and($lower->mouse()->inside())->toBeTrue();
});

it('reads the right button and the other buttons by number', function (): void {
    $input = hiInput();
    [$window, $top] = hiWindow();
    $channel = $top->input();
    $other = function (int $number) use ($window): array {
        $quartz = CGEvent::createMouseEvent(null, CGEventType::OTHER_MOUSE_DOWN, new NSPoint(0.0, 0.0), CGMouseButton::CENTER);
        $quartz->setIntegerValueField(kCGMouseEventButtonNumber, $number);

        return hiQuartz($quartz, $window, 30, 30);
    };
    HiSystem::$buttons = 0b11110;

    hiDeliver($input, hiMouse($window, NSEventType::RIGHT_MOUSE_DOWN, 30, 30), $other(2), $other(3), $other(4));

    expect(array_map(fn (MouseButton $b): bool => $channel->mouse()->isDown($b), MouseButton::cases()))
        ->toBe([false, true, true, true, true]);
});

it('carries the keys held with a click', function (): void {
    $input = hiInput();
    [$window, $top] = hiWindow();
    $top->input();
    HiSystem::$buttons = 1;

    hiDeliver($input, hiMouse($window, NSEventType::LEFT_MOUSE_DOWN, 30, 30, NSEventModifierFlags::COMMAND->value | NSEventModifierFlags::SHIFT->value));

    $sent = array_values(array_filter(HiSystem::$mail, fn (object $m): bool => $m instanceof PointerButtonChanged));
    expect($sent[0]->modifiers)->toEqual(new Modifiers(shift: true, meta: true));
});

it('gives a canvas whose input is not open nothing, and leaves it out of focus', function (): void {
    $input = hiInput();
    [$window, , $bottom] = hiWindow();
    HiSystem::$buttons = 1;

    hiDeliver($input, hiMouse($window, NSEventType::LEFT_MOUSE_DOWN, 30, 150));

    expect(hiMailSent())->toBe([])
        ->and($bottom->native()->acceptsFirstResponder())->toBeFalse()
        ->and($window->native()->firstResponder())->not->toBe($bottom->native());
});

/* Focus and keys. */

it('accepts first responder once its input is open, so a click gives it the keyboard', function (): void {
    // AppKit hands first responder to the clicked view in a window that can become key: a
    // hidden window cannot, so the click itself is checked in a shown window
    hiInput();
    [, $top] = hiWindow();
    $before = $top->native()->acceptsFirstResponder();

    $top->input();

    expect($before)->toBeFalse()
        ->and($top->native()->acceptsFirstResponder())->toBeTrue();
});

it('takes the keyboard from code', function (): void {
    hiInput();
    [$window, $top] = hiWindow();
    $top->input();

    $top->focus();

    expect($window->native()->firstResponder())->toBe($top->native());
});

it('reads a key down and up, and the text it typed, through interpretKeyEvents', function (): void {
    $input = hiInput();
    [$window, $top] = hiWindow();
    $channel = $top->input();
    $top->focus();
    HiSystem::$keys = [0x00];

    $took = $top->keyed(hiKey($window, 0x00, true, 'a'), true);
    $input->poll();
    $down = [$channel->keyboard()->isDown(Key::A), $channel->keyboard()->isPressed(Key::A), $channel->keyboard()->text()];
    $top->keyed(hiKey($window, 0x00, false, 'a'), false);
    $input->poll();

    expect($took)->toBeTrue()
        ->and($down)->toBe([true, true, 'a'])
        ->and($channel->keyboard()->isDown(Key::A))->toBeFalse()
        ->and($channel->keyboard()->wasReleased(Key::A))->toBeTrue()
        ->and(hiMailSent())->toBe([KeyChanged::class, TextTyped::class, KeyChanged::class]);
});

it('types again on a held key\'s repeats without pressing it again', function (): void {
    $input = hiInput();
    [$window, $top] = hiWindow();
    $channel = $top->input();
    $top->focus();
    HiSystem::$keys = [0x00];

    $top->keyed(hiKey($window, 0x00, true, 'a'), true);
    $top->keyed(hiKey($window, 0x00, true, 'a', repeat: true), true);
    $top->keyed(hiKey($window, 0x00, true, 'a', repeat: true), true);
    $input->poll();

    expect($channel->keyboard()->text())->toBe('aaa')
        ->and(array_count_values(hiMailSent())[KeyChanged::class])->toBe(1);
});

it('takes a key that asks for a command, without typing anything', function (): void {
    $input = hiInput();
    [$window, $top] = hiWindow();
    $channel = $top->input();
    $top->focus();

    HiSystem::$keys = [0x7B];

    // the left arrow: interpretKeyEvents asks for moveLeft:
    $took = $top->keyed(hiKey($window, 0x7B, true, "\u{F702}"), true);
    $input->poll();

    expect($took)->toBeTrue()
        ->and($channel->keyboard()->isDown(Key::LEFT))->toBeTrue()
        ->and($channel->keyboard()->text())->toBe('');
});

it('passes keys on when its input is not open', function (): void {
    hiInput();
    [$window, $top] = hiWindow();

    expect($top->keyed(hiKey($window, 0x00, true, 'a'), true))->toBeFalse()
        ->and($top->flagged(hiFlags($window, 0x38, 0)))->toBeFalse()
        ->and($top->typed('a'))->toBeFalse();
});

it('reads each side of a modifier, and Caps Lock as one press a change', function (): void {
    $input = hiInput();
    [$window, $top] = hiWindow();
    $channel = $top->input();
    $top->focus();
    $shift = NSEventModifierFlags::SHIFT->value;
    HiSystem::$keys = [0x3C];

    $top->flagged(hiFlags($window, 0x3C, $shift | 0x04));          // right shift down
    $input->poll();
    $held = [$channel->keyboard()->isDown(Key::RIGHT_SHIFT), $channel->keyboard()->isDown(Key::LEFT_SHIFT), $channel->keyboard()->modifiers()->shift];
    $top->flagged(hiFlags($window, 0x3C, 0));
    $top->flagged(hiFlags($window, 0x39, NSEventModifierFlags::CAPS_LOCK->value));
    $input->poll();

    expect($held)->toBe([true, false, true])
        ->and($channel->keyboard()->isDown(Key::RIGHT_SHIFT))->toBeFalse()
        ->and($channel->keyboard()->modifiers()->shift)->toBeFalse()
        ->and($channel->keyboard()->isDown(Key::CAPS_LOCK))->toBeFalse()
        ->and($channel->keyboard()->isPressed(Key::CAPS_LOCK))->toBeTrue();
});

it('lets go of its keys when it stops being first responder, keeping a button it captured', function (): void {
    $input = hiInput();
    [$window, $top] = hiWindow();
    $channel = $top->input();
    $top->focus();
    HiSystem::$buttons = 1;
    HiSystem::$keys = [0x00];
    hiDeliver($input, hiMouse($window, NSEventType::LEFT_MOUSE_DOWN, 30, 30));
    $top->keyed(hiKey($window, 0x00, true, 'a'), true);
    $input->poll();
    hiMailSent();

    $window->native()->makeFirstResponder(null);
    $input->poll();

    expect($channel->keyboard()->isDown(Key::A))->toBeFalse()
        ->and($channel->keyboard()->wasReleased(Key::A))->toBeTrue()
        ->and($channel->mouse()->isDown(MouseButton::LEFT))->toBeTrue()
        ->and(hiMailSent())->toBe([KeyChanged::class]);
});

it('catches a key-up AppKit keeps from the first responder while Command is down', function (): void {
    $input = hiInput();
    [$window, $top] = hiWindow();
    $channel = $top->input();
    $top->focus();
    $command = NSEventModifierFlags::COMMAND->value;
    HiSystem::$keys = [0x00];
    $top->keyed(hiKey($window, 0x00, true, 'a', $command), true);
    $input->poll();

    hiDeliver($input, hiKey($window, 0x00, false, 'a', $command));

    expect($channel->keyboard()->isDown(Key::A))->toBeFalse()
        ->and($channel->keyboard()->wasReleased(Key::A))->toBeTrue();
});

/* Scrolling. */

it('reads a notched wheel in notches, dy > 0 showing content further down', function (): void {
    $input = hiInput();
    [$window, $top] = hiWindow();
    $channel = $top->input();

    // Quartz's positive wheel 1 is the wheel rolled away from the user: content further up
    hiDeliver($input, hiScroll($window, 40, 40, CGScrollEventUnit::LINE, 1), hiScroll($window, 40, 40, CGScrollEventUnit::LINE, 2));

    expect($channel->mouse()->wheel())->toBe(['dx' => 0.0, 'dy' => -3.0])
        ->and($channel->mouse()->precise())->toBeFalse()
        ->and($channel->mouse()->phase())->toBe(ScrollPhase::NONE)
        ->and(array_count_values(hiMailSent())[PointerScrolled::class])->toBe(2);
});

it('reads a precise scroll in the canvas\'s pixels, both ways', function (): void {
    $input = hiInput();
    [$window, $top] = hiWindow();
    $channel = $top->input();

    // wheel 2 positive is toward the left: content further left
    hiDeliver($input, hiScroll($window, 40, 40, CGScrollEventUnit::PIXEL, 10, 6));

    $scale = hiScale($window);
    expect($channel->mouse()->wheel())->toBe(['dx' => -6 * $scale, 'dy' => -10 * $scale])
        ->and($channel->mouse()->precise())->toBeTrue();
});

it('reads a trackpad scroll\'s phases and its momentum after', function (array $phases, ScrollPhase $expected): void {
    $input = hiInput();
    [$window, $top] = hiWindow();
    $channel = $top->input();

    hiDeliver($input, hiScroll($window, 40, 40, CGScrollEventUnit::PIXEL, 3, 0, ...$phases));

    expect($channel->mouse()->phase())->toBe($expected);
})->with([
    'began' => [[1, 0], ScrollPhase::BEGAN],
    'changed' => [[2, 0], ScrollPhase::CHANGED],
    'ended' => [[4, 0], ScrollPhase::ENDED],
    'momentum starting' => [[0, 1], ScrollPhase::MOMENTUM],
    'momentum going' => [[0, 2], ScrollPhase::MOMENTUM],
    'momentum ending' => [[0, 3], ScrollPhase::MOMENTUM_ENDED],
]);

it('begins a scroll gesture once when the fingers rest before they move', function (): void {
    $input = hiInput();
    [$window, $top] = hiWindow();
    $top->input();
    HiSystem::$mail = [];

    // measured on a real trackpad: may-begin (128) as the fingers touch, then began (1) as they move
    hiDeliver($input,
        hiScroll($window, 40, 40, CGScrollEventUnit::PIXEL, 0, 0, 128),
        hiScroll($window, 40, 40, CGScrollEventUnit::PIXEL, 3, 0, 1),
        hiScroll($window, 40, 40, CGScrollEventUnit::PIXEL, 3, 0, 2),
        hiScroll($window, 40, 40, CGScrollEventUnit::PIXEL, 0, 0, 4),
        hiScroll($window, 40, 40, CGScrollEventUnit::PIXEL, 0, 0, 128),
        hiScroll($window, 40, 40, CGScrollEventUnit::PIXEL, 0, 0, 8),
        hiScroll($window, 40, 40, CGScrollEventUnit::PIXEL, 3, 0, 1),
    );

    $phases = array_map(fn (PointerScrolled $m): ScrollPhase => $m->phase, array_values(array_filter(HiSystem::$mail, fn (object $m): bool => $m instanceof PointerScrolled)));
    expect($phases)->toBe([
        ScrollPhase::BEGAN, ScrollPhase::CHANGED, ScrollPhase::CHANGED, ScrollPhase::ENDED,
        ScrollPhase::BEGAN, ScrollPhase::ENDED,
        ScrollPhase::BEGAN,
    ]);
});

it('sends a scroll to the canvas under the pointer, with where it is', function (): void {
    $input = hiInput();
    [$window, $top, $bottom] = hiWindow();
    [$upper, $lower] = [$top->input(), $bottom->input()];

    hiDeliver($input, hiScroll($window, 40, 140, CGScrollEventUnit::LINE, 1));

    expect($upper->mouse()->wheel())->toBe(['dx' => 0.0, 'dy' => 0.0])
        ->and($lower->mouse()->wheel())->toBe(['dx' => 0.0, 'dy' => -1.0])
        ->and($lower->mouse()->y())->toBe(40 * hiScale($window));
});

/* Trackpad gestures and force. */

it('adds up a pinch over the frame and over the gesture', function (): void {
    $input = hiInput();
    [$window, $top] = hiWindow();
    $channel = $top->input();
    $mouse = $channel->mouse();

    hiDeliver($input,
        hiGesture($window, 40, 40, 8, [132 => 1], [113 => 0.25]),
        hiGesture($window, 40, 40, 8, [132 => 2], [113 => 0.125]),
    );
    $first = [$mouse->magnification(), $mouse->pinchTotal(), $mouse->pinchPhase()];
    $channel->settle();
    hiDeliver($input, hiGesture($window, 40, 40, 8, [132 => 4], [113 => -0.0625]));
    $ending = [$mouse->magnification(), $mouse->pinchTotal(), $mouse->pinchPhase()];
    $channel->settle();

    expect($first)->toBe([0.375, 0.375, GesturePhase::CHANGED])
        ->and($ending)->toBe([-0.0625, 0.3125, GesturePhase::ENDED])
        ->and([$mouse->magnification(), $mouse->pinchPhase()])->toBe([0.0, GesturePhase::NONE])
        ->and(array_count_values(hiMailSent())[Pinched::class])->toBe(3);
});

it('starts a pinch\'s total again as a new gesture begins', function (): void {
    $input = hiInput();
    [$window, $top] = hiWindow();
    $mouse = $top->input()->mouse();

    hiDeliver($input,
        hiGesture($window, 40, 40, 8, [132 => 1], [113 => 0.5]),
        hiGesture($window, 40, 40, 8, [132 => 4], [113 => 0.0]),
        hiGesture($window, 40, 40, 8, [132 => 1], [113 => 0.25]),
    );

    expect($mouse->pinchTotal())->toBe(0.25);
});

it('adds up a rotation, both ways', function (): void {
    $input = hiInput();
    [$window, $top] = hiWindow();
    $mouse = $top->input()->mouse();

    hiDeliver($input,
        hiGesture($window, 40, 40, 5, [132 => 1], [114 => 12.5]),
        hiGesture($window, 40, 40, 5, [132 => 2], [114 => -30.0]),
    );

    expect([$mouse->rotation(), $mouse->rotationTotal(), $mouse->rotationPhase()])->toBe([-17.5, -17.5, GesturePhase::CHANGED])
        ->and(array_count_values(hiMailSent())[Rotated::class])->toBe(2);
});

it('reads a force click on the canvas it was pressed on, through its stages', function (): void {
    $input = hiInput();
    [$window, $top, $bottom] = hiWindow();
    [$upper, $lower] = [$top->input(), $bottom->input()];
    HiSystem::$buttons = 1;
    hiDeliver($input, hiMouse($window, NSEventType::LEFT_MOUSE_DOWN, 30, 30));

    // float pressure and transition in AppKit: these values are exact in 32 bits
    hiDeliver($input, hiGesture($window, 30, 150, 32, [143 => 1], [119 => 0.5, 113 => 0.25]));
    $clicked = [$upper->mouse()->pressure(), $upper->mouse()->stage(), $upper->mouse()->stageTransition()];
    hiDeliver($input, hiGesture($window, 30, 150, 32, [143 => 2], [119 => 0.625, 113 => 0.0]));

    expect($clicked)->toBe([0.5, 1, 0.25])
        ->and([$upper->mouse()->pressure(), $upper->mouse()->stage(), $upper->mouse()->stageTransition()])->toBe([0.625, 2, 0.0])
        ->and($lower->mouse()->stage())->toBe(0)
        ->and(array_count_values(hiMailSent())[ForceChanged::class])->toBe(2);
});

/* What the system holds, and focus leaving the application. */

it('lets go of a button and a key the system no longer holds', function (): void {
    $input = hiInput();
    [$window, $top] = hiWindow();
    $channel = $top->input();
    $top->focus();
    HiSystem::$buttons = 1;
    HiSystem::$keys = [0x38, 0x00];
    hiDeliver($input, hiMouse($window, NSEventType::LEFT_MOUSE_DOWN, 30, 30));
    $top->flagged(hiFlags($window, 0x38, NSEventModifierFlags::SHIFT->value | 0x02));
    $top->keyed(hiKey($window, 0x00, true, 'A', NSEventModifierFlags::SHIFT->value), true);
    $input->poll();
    $held = [$channel->mouse()->isDown(MouseButton::LEFT), $channel->keyboard()->downKeys(), $channel->keyboard()->modifiers()->shift];

    // a tracking loop dequeued the releases: the system holds nothing now
    [HiSystem::$buttons, HiSystem::$keys] = [0, []];
    $input->poll();

    expect($held)->toBe([true, [Key::LEFT_SHIFT, Key::A], true])
        ->and($channel->mouse()->isDown(MouseButton::LEFT))->toBeFalse()
        ->and($channel->keyboard()->downKeys())->toBe([])
        ->and($channel->keyboard()->modifiers()->shift)->toBeFalse();
});

it('lets go of everything when the application stops being active', function (): void {
    $input = hiInput();
    [$window, $top] = hiWindow();
    $channel = $top->input();
    $top->focus();
    HiSystem::$buttons = 1;
    HiSystem::$keys = [0x00];
    hiDeliver($input, hiMouse($window, NSEventType::LEFT_MOUSE_DOWN, 30, 30), hiGesture($window, 30, 30, 32, [143 => 1], [119 => 0.5]));
    $top->keyed(hiKey($window, 0x00, true, 'a'), true);
    $input->poll();

    NSNotificationCenter::defaultCenter()->postNotificationNameObject(NSApplicationDidResignActiveNotification, null);
    $input->poll();

    expect($channel->mouse()->isDown(MouseButton::LEFT))->toBeFalse()
        ->and($channel->keyboard()->isDown(Key::A))->toBeFalse()
        ->and($channel->mouse()->stage())->toBe(0);
});

it('closes every channel and stops listening as it disconnects', function (): void {
    $input = hiInput();
    [$window, $top] = hiWindow();
    $channel = $top->input();

    $input->destroy();
    hiDeliver($input, hiMouse($window, NSEventType::MOUSE_MOVED, 30, 30));

    expect($channel->closed())->toBeTrue()
        ->and(hiMailSent())->toBe([])
        ->and($top->keyboardInput())->toBeNull()
        ->and($top->native()->acceptsFirstResponder())->toBeFalse();
});
