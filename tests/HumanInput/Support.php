<?php

declare(strict_types=1);

/*
 * The human-input tests run in a real Venusian application: TOPSIDE_APP names one that requires
 * topside and this package (apps/topside-dev), booted once with AppKit as its toolkit and the
 * 'appkit' input backend. Run them with that application's Pest and autoloader:
 *
 *     TOPSIDE_APP=…/apps/topside-dev zhp …/apps/topside-dev/vendor/bin/pest \
 *         --bootstrap=…/apps/topside-dev/vendor/autoload.php tests/HumanInput tests/Input/PadSourceTest.php
 *
 * Windows are opened and never presented: nothing is shown and focus is not taken from the user.
 * A hidden window lays out, hit-tests and converts points as a shown one does, and the pointer's
 * events reach the backend's monitor through NSApp sendEvent:. A hidden window is never key, so
 * the keys a canvas takes as first responder are handed to its view's own keyDown:/keyUp: handlers,
 * as AppKit hands them to the first responder of the key window.
 */

use Jovian\Toolkits\Appkit\AppkitWindow;
use Jovian\Toolkits\Appkit\Input\AppkitInputBackend;
use Jovian\Toolkits\Appkit\Primitives\AppkitCanvas;
use Surface\Contracts\HumanInput\InputContext;
use Surface\HumanInput\HumanInput;
use Surface\HumanInput\HumanInputManager;
use Surface\Toolkits\Toolkits;
use Voyager\Contracts\Core\Kernel;

/** What the system says is held, as the backend asks after each poll: tests set it. */
final class HiSystem
{
    public static int $buttons = 0;

    /** @var list<int> macOS key codes */
    public static array $keys = [];

    /** @var list<object> mail the manager sent */
    public static array $mail = [];
}

/** The application, booted once with AppKit and the 'appkit' backend. */
function hiApp(): object
{
    static $app = null;
    if (! is_null($app)) {
        return $app;
    }

    $base = getenv('TOPSIDE_APP');
    if ($base === false || ! is_file("{$base}/bootstrap/main.php")) {
        throw new RuntimeException('Set TOPSIDE_APP to a Venusian application that requires venusian/topside and this package, such as apps/topside-dev.');
    }
    foreach (['PRESENTATION_TOOLKIT_DRIVER' => 'appkit', 'TOOLKIT_BRIDGE_DEFAULT' => 'appkit', 'HUMAN_INPUT' => 'true', 'HUMAN_INPUT_BACKEND' => 'appkit'] as $name => $value) {
        putenv("{$name}={$value}");
        $_ENV[$name] = $_SERVER[$name] = $value;
    }

    $app = require "{$base}/bootstrap/main.php";
    $app->make(Kernel::class)->bootstrap();

    return $app;
}

/**
 * The manager with a fresh 'appkit' backend that asks HiSystem what the system holds, resolved and
 * listening. The one the application booted with is let go first.
 */
function hiInput(): HumanInputManager
{
    hiApp();
    $manager = HumanInput::manager();
    $manager->backend()?->disconnect();
    $manager->forgetDrivers();
    [HiSystem::$buttons, HiSystem::$keys, HiSystem::$mail] = [0, [], []];

    $manager->extend('appkit', fn (): AppkitInputBackend => new AppkitInputBackend(
        static fn (): int => HiSystem::$buttons,
        static fn (int $code): bool => in_array($code, HiSystem::$keys, true),
    ));
    $manager->mailTo(static function (object $mail): void {
        HiSystem::$mail[] = $mail;
    });
    $manager->resolve(new InputContext('appkit', 'velvet', 'mac'));
    $manager->connect();

    return $manager;
}

/**
 * A hidden 300x200 window with two canvases stacked, each 300x100 points: 'top' and 'bottom'.
 *
 * @return array{AppkitWindow, AppkitCanvas, AppkitCanvas}
 */
function hiWindow(string $name = 'hi'): array
{
    $window = Toolkits::window()->open($name, 300, 200);
    $column = $window->column('m');
    $column->fill();
    $top = $column->canvas('top');
    $top->fill();
    $bottom = $column->canvas('bottom');
    $bottom->fill();
    $window->native()->contentView()->layoutSubtreeIfNeeded();

    return [$window, $top, $bottom];
}

function hiCloseWindows(): void
{
    hiApp();
    Toolkits::window()->closeAll();
}

/** The window's backing scale: pixels a point. */
function hiScale(AppkitWindow $window): float
{
    return $window->native()->backingScaleFactor();
}

/** A point of the window's content, given from its top left, in the window's own coordinates (origin bottom left). */
function hiWindowPoint(AppkitWindow $window, float $x, float $y): NSPoint
{
    return new NSPoint($x, $window->native()->contentView()->bounds()->height - $y);
}

/** A mouse event of $type at ($x, $y) of the window's content, from its top left. */
function hiMouse(AppkitWindow $window, NSEventType $type, float $x, float $y, int $flags = 0): NSEvent
{
    return NSEvent::mouseEventWithTypeLocationModifierFlagsTimestampWindowNumberContextEventNumberClickCountPressure(
        $type, hiWindowPoint($window, $x, $y), $flags, 0.0, $window->native()->windowNumber(), null, 0, 1, 1.0,
    );
}

/**
 * A Quartz event at ($x, $y) of the window's content. AppKit gives an event made from a Quartz
 * event no window-local point a caller chooses (it reads that from a private field), so these
 * events are handed to the backend with their window and point by hiDeliverAt(), not sent.
 */
function hiQuartz(CGEvent $quartz, AppkitWindow $window, float $x, float $y): array
{
    return [NSEvent::eventWithCGEvent($quartz), $window, $x, $y];
}

/** A scroll at ($x, $y): $vertical and $horizontal in lines or pixels, with the Quartz scroll and momentum phases. */
function hiScroll(AppkitWindow $window, float $x, float $y, CGScrollEventUnit $units, int $vertical, int $horizontal = 0, int $phase = 0, int $momentum = 0): array
{
    $quartz = CGEvent::createScrollWheelEvent2(null, $units, 2, $vertical, $horizontal, 0);
    $quartz->setIntegerValueField(99, $phase);                       // kCGScrollWheelEventScrollPhase
    $quartz->setIntegerValueField(123, $momentum);                   // kCGScrollWheelEventMomentumPhase

    return hiQuartz($quartz, $window, $x, $y);
}

/**
 * A trackpad gesture at ($x, $y), as Quartz carries one: type 29, the IOHID kind in field 110
 * (8 zoom, 5 rotation, 32 force), then the kind's fields.
 *
 * @param array<int, int> $integers
 * @param array<int, float> $doubles
 */
function hiGesture(AppkitWindow $window, float $x, float $y, int $kind, array $integers = [], array $doubles = []): array
{
    $quartz = CGEvent::create(null);
    $quartz->setType(29);
    $quartz->setIntegerValueField(110, $kind);
    foreach ($integers as $field => $value) {
        $quartz->setIntegerValueField($field, $value);
    }
    foreach ($doubles as $field => $value) {
        $quartz->setDoubleValueField($field, $value);
    }

    return hiQuartz($quartz, $window, $x, $y);
}

/** A key event for the window. */
function hiKey(AppkitWindow $window, int $code, bool $down, string $characters = '', int $flags = 0, bool $repeat = false): NSEvent
{
    return NSEvent::keyEventWithTypeLocationModifierFlagsTimestampWindowNumberContextCharactersCharactersIgnoringModifiersIsARepeatKeyCode(
        $down ? NSEventType::KEY_DOWN : NSEventType::KEY_UP, new NSPoint(0.0, 0.0), $flags, 0.0, $window->native()->windowNumber(), null, $characters, $characters, $repeat, $code,
    );
}

function hiFlags(AppkitWindow $window, int $code, int $flags): NSEvent
{
    return NSEvent::keyEventWithTypeLocationModifierFlagsTimestampWindowNumberContextCharactersCharactersIgnoringModifiersIsARepeatKeyCode(
        NSEventType::FLAGS_CHANGED, new NSPoint(0.0, 0.0), $flags, 0.0, $window->native()->windowNumber(), null, '', '', false, $code,
    );
}

/**
 * AppKit dispatches the events, as the session's pump does, and the manager polls once. An event
 * from hiQuartz() goes to the backend's router with its window and point, as the monitor would
 * hand it on with what AppKit read.
 *
 * @param NSEvent|array{NSEvent, AppkitWindow, float, float} ...$events
 */
function hiDeliver(HumanInputManager $input, NSEvent|array ...$events): void
{
    foreach ($events as $event) {
        if ($event instanceof NSEvent) {
            NSApplication::sharedApplication()->sendEvent($event);

            continue;
        }
        [$quartz, $window, $x, $y] = $event;
        $input->backend()->dispatchedAt($quartz, $window->native(), hiWindowPoint($window, $x, $y));
    }
    $input->poll();
}

/** @return list<class-string> the classes of the mail sent since the last call */
function hiMailSent(): array
{
    [$mail, HiSystem::$mail] = [HiSystem::$mail, []];

    return array_map(fn (object $m): string => $m::class, $mail);
}

/** The descriptor number of a stream, found through /dev/fd. */
function hiStreamDescriptor($stream): int
{
    $want = fstat($stream);
    foreach (scandir('/dev/fd') as $entry) {
        // the listing's own descriptor is closed by now: file_exists() skips it without a warning
        if (! ctype_digit($entry) || ! file_exists('/dev/fd/'.$entry)) {
            continue;
        }
        $stat = stat('/dev/fd/'.$entry);
        if ($stat !== false && $stat['ino'] === $want['ino'] && $stat['mode'] === $want['mode']) {
            return (int) $entry;
        }
    }

    throw new RuntimeException('No /dev/fd entry matches the stream');
}

// Booted as the tests load, before any runs: the application installs its error handlers as it
// boots, and a test that leaves handlers it did not find is risky.
hiApp();
