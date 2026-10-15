<?php

declare(strict_types=1);

use Jovian\Toolkits\Appkit\Bridge\AppkitBridgeDriver;
use Jovian\Toolkits\Appkit\Bridge\AppkitSession;
use Jovian\Toolkits\Appkit\Input\AppkitInputEngine;
use Jovian\Toolkits\Appkit\Primitives\AppkitTextArea;
use Jovian\Toolkits\Appkit\Windows\AppkitWindow;
use Jovian\Toolkits\Appkit\Windows\AppkitStagedWindow;
use Surface\HumanInput\InputFrame;
use Surface\Bridge\ToolkitManager;
use Surface\Bridge\ToolkitPump;
use Surface\Contracts\Drawing\LentSurface;
use Surface\Contracts\Drawing\SurfaceBorrower;
use Surface\Contracts\Framebuffers\FormatSpec;
use Surface\Contracts\Framebuffers\Framebuffer;
use Surface\Contracts\Windows\Mail\View\PrimitiveMail;
use Surface\Framebuffers\Native\NativeDirtyFramebuffer;
use Surface\Windows\ToolkitWindowManager;
use Voyager\Config\Repository;
use Voyager\Vessel\ControlPanel;

if (! extension_loaded('appkit')) {
    throw new RuntimeException('venusian-appkit tests need ext-appkit loaded.');
}

const TEST_MENUS = [
    'main' => [
        ['label' => 'App', 'items' => [
            ['role' => 'about', 'label' => 'About'],
            ['separator' => true],
            ['role' => 'quit', 'label' => 'Quit', 'hotkey' => 'q'],
        ]],
        ['label' => 'View', 'items' => [
            ['id' => 'grid', 'label' => 'Show Grid', 'toggle' => true, 'on' => false],
            ['id' => 'view.refresh', 'label' => 'Refresh', 'hotkey' => 'R'],
        ]],
    ],
    'tools' => [
        ['label' => 'Tools', 'items' => [
            ['id' => 'tools.measure', 'label' => 'Measure'],
        ]],
    ],
    'editing' => [
        ['label' => 'App', 'items' => [
            ['role' => 'quit', 'label' => 'Quit', 'hotkey' => 'q'],
        ]],
        ['label' => 'Edit', 'items' => [
            ['role' => 'undo', 'label' => 'Undo'],
            ['role' => 'redo', 'label' => 'Redo'],
            ['separator' => true],
            ['role' => 'cut', 'label' => 'Cut'],
            ['role' => 'copy', 'label' => 'Copy'],
            ['role' => 'paste', 'label' => 'Paste'],
            ['role' => 'select-all', 'label' => 'Select All'],
            ['separator' => true],
            ['id' => 'edit.find', 'label' => 'Find'],
        ]],
    ],
];

/**
 * The one driver for the process: AppKit has one shared application, so every test
 * shares the driver, its session and its windows, through a container like the one
 * the framework hands the bridge.
 */
function driver(): AppkitBridgeDriver
{
    static $driver = null;

    if (is_null($driver)) {
        $container = new ControlPanel();
        $container->registerInstance('config', new Repository([
            'windows' => [
                'about' => ['name' => 'venusian-appkit tests', 'version' => '0.10.0', 'copyright' => null],
                'default_menu' => 'main',
                'menus' => TEST_MENUS,
            ],
        ]));
        $container->registerInstance('toolkit-bridge', $toolkits = new ToolkitManager($container));
        $container->registerInstance('toolkit-windows', new ToolkitWindowManager($toolkits, TEST_MENUS, 'main'));
        $driver = new AppkitBridgeDriver($container);
    }

    return $driver;
}

function session(): AppkitSession
{
    return driver()->connect();
}

/** Mail the session is holding for a loop, taken out. */
function takeMail(AppkitSession $session): array
{
    return (function (): array {
        [$mail, $this->outbox] = [$this->outbox, []];

        return $mail;
    })->call($session);
}

/** The primitives' own mail the session is holding, taken out; window and menu mail is dropped. */
function viewMail(AppkitSession $session): array
{
    return array_values(array_filter(takeMail($session), fn (object $mail): bool => $mail instanceof PrimitiveMail));
}

/**
 * Pump until the window is on screen and two pumps in a row see the same clip bounds and
 * document frame: the window's first layout has landed, so what follows is the test's own.
 */
function settled(AppkitWindow $window, AppkitTextArea $area, float $seconds = 3.0): bool
{
    $last = null;

    return pumpUntil(function () use ($window, $area, &$last): bool {
        $bounds = $area->native()->contentView()->bounds();
        $document = $area->native()->documentView()->frame();
        $now = [$bounds->x, $bounds->y, $bounds->width, $bounds->height, $document->width, $document->height];
        $settled = $window->native()->isVisible() && $bounds->height > 0.0 && $now === $last;
        $last = $now;

        return $settled;
    }, $seconds);
}

/** Pump AppKit for $seconds through the real sleeper, which flushes latest mail after each pump as the loop does. */
function pumpFor(float $seconds): void
{
    $pump = new ToolkitPump(session());
    $until = microtime(true) + $seconds;
    while (microtime(true) < $until) {
        $pump->sleep(10_000_000);
    }
}

/** Pump through the real sleeper until $until() holds or $seconds pass; whether it held. */
function pumpUntil(callable $until, float $seconds): bool
{
    $pump = new ToolkitPump(session());
    $limit = microtime(true) + $seconds;
    while (! $until()) {
        if (microtime(true) >= $limit) {
            return false;
        }
        $pump->sleep(10_000_000);
    }

    return true;
}

/** Perform a built item as if the user chose it. */
function choose(NSMenuItem $item): void
{
    $menu = $item->menu();
    $menu->performActionForItemAtIndex($menu->indexOfItem($item));
}

/** A borrower that keeps what it was asked to present into; its framebuffer is a plain dirty one. */
final class LayerBorrower implements SurfaceBorrower
{
    /** @var list<LentSurface> */
    public array $presented = [];

    public Framebuffer $frame;

    public function __construct()
    {
        $this->frame = new NativeDirtyFramebuffer(FormatSpec::rgba8(), 8, 8);
    }

    public function framebuffer(): Framebuffer
    {
        return $this->frame;
    }

    public function lendingHandles(): array
    {
        return [];
    }

    public function presentInto(LentSurface $surface): bool
    {
        $this->presented[] = $surface;

        return true;
    }
}

/** A frame an hour long: within a test only reads end it. */
function inputFrame(): InputFrame
{
    return new InputFrame(fn (): int => 3_600_000_000_000);
}

/**
 * A staged window on screen for input tests, its centre found by the window-at-point lookup
 * (a window not yet on screen is not found, measured). $x and $y place it, so two do not overlap.
 */
function inputWindow(string $name = 'keys', int $x = 120, int $y = 160): AppkitStagedWindow
{
    driver()->getStaged($name)?->close();
    $window = driver()->openStaged($name, 320, 200, ['x' => $x, 'y' => $y]);
    $number = $window->native()->windowNumber();
    pumpUntil(fn (): bool => NSWindow::windowNumberAtPointBelowWindowWithWindowNumber(screenPoint($window, 160.0, 100.0), 0) === $number, 5.0)
        || throw new RuntimeException("Staged window '{$name}' never came on screen.");

    return $window;
}

/** The AppKit screen point (origin bottom left of the main screen) of ($x, $y) in $window's content, measured from its top left. */
function screenPoint(AppkitStagedWindow $window, float $x, float $y): NSPoint
{
    $native = $window->native();
    $content = $native->contentRectForFrameRect($native->frame());

    return new NSPoint($content->x + $x, $content->y + $content->height - $y);
}

/** The Quartz point (origin top left of the main screen) of an AppKit screen point. */
function quartzPoint(NSPoint $point): NSPoint
{
    return new NSPoint($point->x, NSScreen::screens()[0]->frame()->height - $point->y);
}

function keyEvent(AppkitStagedWindow $window, int $code, bool $down, string $characters = '', int $flags = 0, bool $repeat = false): NSEvent
{
    return NSEvent::keyEventWithTypeLocationModifierFlagsTimestampWindowNumberContextCharactersCharactersIgnoringModifiersIsARepeatKeyCode(
        $down ? NSEventType::KEY_DOWN : NSEventType::KEY_UP, new NSPoint(), $flags, 0.0, $window->native()->windowNumber(), null, $characters, $characters, $repeat, $code,
    );
}

function flagsEvent(AppkitStagedWindow $window, int $code, int $flags): NSEvent
{
    return NSEvent::keyEventWithTypeLocationModifierFlagsTimestampWindowNumberContextCharactersCharactersIgnoringModifiersIsARepeatKeyCode(
        NSEventType::FLAGS_CHANGED, new NSPoint(), $flags, 0.0, $window->native()->windowNumber(), null, '', '', false, $code,
    );
}

/** A left or right click event at ($x, $y) of $window's content, from its top left. */
function clickEvent(AppkitStagedWindow $window, NSEventType $type, float $x, float $y): NSEvent
{
    $frame = $window->native()->frame();
    $at = screenPoint($window, $x, $y);

    return NSEvent::mouseEventWithTypeLocationModifierFlagsTimestampWindowNumberContextEventNumberClickCountPressure(
        $type, new NSPoint($at->x - $frame->x, $at->y - $frame->y), 0, 0.0, $window->native()->windowNumber(), null, 0, 1, 1.0,
    );
}

/** An other-button event (2 middle, 3 and 4 the side buttons) at a screen point: AppKit has no constructor that sets the button. */
function otherButtonEvent(NSPoint $at, int $button, bool $down): NSEvent
{
    $quartz = CGEvent::createMouseEvent(null, $down ? CGEventType::OTHER_MOUSE_DOWN : CGEventType::OTHER_MOUSE_UP, quartzPoint($at), CGMouseButton::CENTER);
    $quartz->setIntegerValueField(kCGMouseEventButtonNumber, $button);

    return NSEvent::eventWithCGEvent($quartz);
}

/** A moved event at a screen point with motion deltas: window number 0, as a CGEvent's is. */
function moveEvent(NSPoint $at, int $dx = 0, int $dy = 0): NSEvent
{
    $quartz = CGEvent::createMouseEvent(null, CGEventType::MOUSE_MOVED, quartzPoint($at), CGMouseButton::LEFT);
    $quartz->setIntegerValueField(kCGMouseEventDeltaX, $dx);
    $quartz->setIntegerValueField(kCGMouseEventDeltaY, $dy);

    return NSEvent::eventWithCGEvent($quartz);
}

/** A scroll at a screen point: $vertical and $horizontal in lines or pixels. */
function scrollEvent(NSPoint $at, CGScrollEventUnit $units, int $vertical, int $horizontal = 0): NSEvent
{
    $quartz = CGEvent::createScrollWheelEvent2(null, $units, 2, $vertical, $horizontal, 0);
    $quartz->setLocation(quartzPoint($at));

    return NSEvent::eventWithCGEvent($quartz);
}

/**
 * An appkit input engine on the shared driver's connected session, let go by letGoOfInput().
 * The pointer rests where $pointer says (far off every screen unless given), and every button and
 * key the engine saw go down stays held unless $buttons and $keys say otherwise, so the real
 * pointer, mouse and keyboard never decide a test.
 *
 * @param (Closure(): NSPoint)|null $pointer
 * @param (Closure(): int)|null $buttons the pressed-buttons mask
 * @param (Closure(int): bool)|null $keys whether a key code is down
 */
function appkitInput(?Closure $pointer = null, ?Closure $buttons = null, ?Closure $keys = null): AppkitInputEngine
{
    session();
    $engine = new AppkitInputEngine(
        inputFrame(),
        driver(),
        $pointer ?? fn (): NSPoint => new NSPoint(-20_000.0, -20_000.0),
        $buttons ?? fn (): int => 0b11111,
        $keys ?? fn (int $code): bool => true,
    );
    $GLOBALS['appkit_input_engines'][] = $engine;

    return $engine->connect();
}

/** Disconnects every engine appkitInput() made: their taps and observers leave. */
function letGoOfInput(): void
{
    foreach ($GLOBALS['appkit_input_engines'] ?? [] as $engine) {
        $engine->disconnect();
    }
    $GLOBALS['appkit_input_engines'] = [];
}

/** Posts the events, pumps the session once as the loop's poller does, and polls the engine. */
function deliver(AppkitInputEngine $engine, NSEvent ...$events): void
{
    $session = session();
    $session->pump(0);
    foreach ($events as $event) {
        $session->application()->postEventAtStart($event, false);
    }
    $session->pump(0);
    $engine->poll();
}
