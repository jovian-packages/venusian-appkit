<?php

declare(strict_types=1);

use Jovian\Toolkits\Appkit\Input\GameControllerPadSource;
use Jovian\Toolkits\Appkit\Input\GameControllers;
use Jovian\Toolkits\Appkit\Input\GcReading;
use Surface\Contracts\HumanInput\GamepadAxis;
use Surface\Contracts\HumanInput\GamepadButton;
use Surface\Contracts\HumanInput\HumanInputException;
use Surface\HumanInput\Devices\GameController;
use Surface\HumanInput\Devices\GamePad;

/*
 * GameController gives input only to the active app and has no virtual pads on macOS, so the
 * source is tested over controllers a fake hands it; the last test runs the system controllers
 * against whatever this Mac has.
 */

/** Controllers that read, grant and notify as the test sets them. */
final class FakeControllers implements GameControllers
{
    /** @var list<GcReading> */
    public array $readings = [];

    public int $asked = 0;

    public bool $watching = false;

    public bool $active = true;

    public ?Closure $connected = null;

    public ?Closure $resigned = null;

    public function __construct(public bool $grant = true) {}

    public function read(): array
    {
        return $this->readings;
    }

    public function watch(Closure $connected, Closure $resigned): void
    {
        [$this->connected, $this->resigned, $this->watching] = [$connected, $resigned, true];
    }

    public function unwatch(): void
    {
        [$this->connected, $this->resigned, $this->watching] = [null, null, false];
    }

    public function monitorBackground(): bool
    {
        $this->asked++;

        return $this->grant;
    }

    public function active(): bool
    {
        return $this->active;
    }
}

/** An extended pad reading: every button up and both sticks centred, but those given. */
function extendedReading(string $id = 'gc-1', array $buttons = [], array $axes = [], array $without = [], ?Closure $lights = null): GcReading
{
    $all = array_fill_keys(array_map(fn (GamepadButton $b): string => $b->value, GamepadButton::cases()), false);
    $sticks = array_fill_keys(array_map(fn (GamepadAxis $a): string => $a->value, GamepadAxis::cases()), 0.0);

    return new GcReading($id, 'Fake Pad', array_diff_key(array_merge($all, $buttons), array_flip($without)), array_merge($sticks, $axes), $lights);
}

/** A connected source over $controllers, a session connected while $session says so. */
function gcSource(FakeControllers $controllers, bool &$session = true): GameControllerPadSource
{
    return (new GameControllerPadSource(inputFrame(), function () use (&$session): bool {
        return $session;
    }, $controllers))->connect();
}

it('lists an extended pad as a game controller with what it reads, and no hardware id', function (): void {
    $controllers = new FakeControllers();
    $source = gcSource($controllers);
    $controllers->readings = [extendedReading(buttons: ['north' => true], axes: ['left_x' => 1.0, 'left_y' => -1.0, 'right_trigger' => 0.5])];

    $source->poll();
    $pad = $source->gameControllers()['gc-1'];

    expect($pad)->toBeInstanceOf(GameController::class)
        ->and([$pad->name(), $pad->hardwareId()])->toBe(['Fake Pad', null])
        ->and($pad->axes())->toBe(GamepadAxis::cases())
        ->and($pad->isPressed(GamepadButton::NORTH))->toBeTrue()
        ->and($pad->leftStick())->toBe(['x' => 1.0, 'y' => -1.0])
        ->and($pad->rightTrigger())->toBe(0.5)
        ->and($source->gamePads())->toBe([]);
});

it('supports only the buttons a pad has', function (): void {
    $controllers = new FakeControllers();
    $source = gcSource($controllers);
    $controllers->readings = [extendedReading(without: ['guide', 'back'])];

    $source->poll();
    $pad = $source->gameControllers()['gc-1'];

    expect($pad->supports(GamepadButton::GUIDE))->toBeFalse()
        ->and($pad->supports(GamepadButton::BACK))->toBeFalse()
        ->and($pad->supports(GamepadButton::START))->toBeTrue()
        ->and($pad->isDown(GamepadButton::GUIDE))->toBeFalse();
});

it('lists a pad without sticks as a game pad', function (): void {
    $controllers = new FakeControllers();
    $source = gcSource($controllers);
    $controllers->readings = [new GcReading('gc-2', 'Remote', ['south' => true, 'west' => false, 'start' => false, 'dpad_up' => false, 'dpad_down' => false, 'dpad_left' => false, 'dpad_right' => false], [], null)];

    $source->poll();

    expect($source->gamePads())->toHaveKey('gc-2')
        ->and($source->gameControllers())->toBe([])
        ->and($source->gamePads()['gc-2'])->toBeInstanceOf(GamePad::class)
        ->and($source->gamePads()['gc-2']->isDown(GamepadButton::SOUTH))->toBeTrue();
});

it('drops a pad that went away', function (): void {
    $controllers = new FakeControllers();
    $source = gcSource($controllers);
    $controllers->readings = [extendedReading('gc-1'), extendedReading('gc-3')];
    $source->poll();
    $controllers->readings = [extendedReading('gc-3')];

    $source->poll();

    expect(array_keys($source->gameControllers()))->toBe(['gc-3']);
});

it('asks for background events on the first connect notification, once, and reports what macOS granted', function (bool $grant): void {
    $controllers = new FakeControllers($grant);
    $source = gcSource($controllers);

    $source->poll();
    $before = [$controllers->asked, $source->backgroundMonitoring()];
    ($controllers->connected)();
    $source->poll();
    ($controllers->connected)();
    $source->poll();

    expect($before)->toBe([0, null])
        ->and([$controllers->asked, $source->backgroundMonitoring()])->toBe([1, $grant]);
})->with(['granted' => [true], 'refused, as macOS 15.4.x does' => [false]]);

it('asks once a pad is listed though its connect notification has not come', function (): void {
    // A pad connected before launch can be listed before its notification arrives.
    $controllers = new FakeControllers();
    $source = gcSource($controllers);
    $controllers->readings = [extendedReading()];

    $source->poll();

    expect($controllers->asked)->toBe(1);
});

it('refuses on pad reads, naming macOS, the refusal and sdl3, where macOS refuses background events and no session is connected', function (): void {
    $controllers = new FakeControllers(grant: false);
    $session = false;
    $source = gcSource($controllers, $session);
    $controllers->readings = [extendedReading()];

    $source->poll();
    $source->poll();

    $version = GameControllerPadSource::macosVersion();
    expect(fn () => $source->gameControllers())->toThrow(HumanInputException::class, "gamecontroller: macOS {$version}")
        ->and(fn () => $source->gamePads())->toThrow(HumanInputException::class, "'sdl3'")
        ->and(fn () => $source->gamePads())->toThrow(HumanInputException::class, 'shouldMonitorBackgroundEvents');

    $session = true;

    expect(array_keys($source->gameControllers()))->toBe(['gc-1']);
});

it('reads pads with no session where macOS grants background events', function (): void {
    $controllers = new FakeControllers(grant: true);
    $session = false;
    $source = gcSource($controllers, $session);
    $controllers->readings = [extendedReading(buttons: ['north' => true])];

    $source->poll();

    expect($source->gameControllers()['gc-1']->isDown(GamepadButton::NORTH))->toBeTrue();
});

it('releases pads on resign where macOS refuses background events, and keeps them at rest until the app is active again', function (): void {
    $controllers = new FakeControllers(grant: false);
    $source = gcSource($controllers);
    $controllers->readings = [extendedReading(buttons: ['north' => true], axes: ['left_x' => 0.8])];
    $source->poll();
    $pad = $source->gameControllers()['gc-1'];
    $source->settle();

    ($controllers->resigned)();
    $controllers->active = false;
    $source->poll();
    $released = [$pad->isDown(GamepadButton::NORTH), $pad->wasReleased(GamepadButton::NORTH), $pad->leftStick()['x']];
    $source->poll();
    $frozen = [$pad->isDown(GamepadButton::NORTH), $pad->leftStick()['x']];
    $controllers->active = true;
    $source->poll();

    expect($released)->toBe([false, true, 0.0])
        ->and($frozen)->toBe([false, 0.0])
        ->and([$pad->isDown(GamepadButton::NORTH), $pad->leftStick()['x']])->toBe([true, 0.8]);
});

it('keeps reading through a resign where macOS grants background events', function (): void {
    $controllers = new FakeControllers(grant: true);
    $source = gcSource($controllers);
    $controllers->readings = [extendedReading(buttons: ['north' => true])];
    $source->poll();

    ($controllers->resigned)();
    $controllers->active = false;
    $source->poll();

    expect($source->gameControllers()['gc-1']->isDown(GamepadButton::NORTH))->toBeTrue();
});

it('sets a pad\'s player index through its lights: 0 to 3 light, anything else unsets', function (?int $index, GCControllerPlayerIndex $set): void {
    $lit = [];
    $controllers = new FakeControllers();
    $source = gcSource($controllers);
    $controllers->readings = [extendedReading(lights: function (?int $i) use (&$lit): void {
        $lit[] = GcReading::playerIndex($i);
    })];
    $source->poll();

    $source->gameControllers()['gc-1']->setPlayerIndex($index);

    expect($lit)->toBe([$set]);
})->with([
    'first' => [0, GCControllerPlayerIndex::INDEX_1],
    'fourth' => [3, GCControllerPlayerIndex::INDEX_4],
    'fifth' => [4, GCControllerPlayerIndex::UNSET],
    'none' => [null, GCControllerPlayerIndex::UNSET],
]);

it('watches while connected and lists nothing once disconnected', function (): void {
    $controllers = new FakeControllers();
    $source = gcSource($controllers);
    $controllers->readings = [extendedReading()];
    $source->poll();
    $watching = $controllers->watching;
    $source->disconnect();
    $source->poll();

    expect($watching)->toBeTrue()
        ->and($controllers->watching)->toBeFalse()
        ->and($source->connected())->toBeFalse()
        ->and($source->gameControllers())->toBe([])
        ->and($source->gamePads())->toBe([]);
});

it('runs the main run loop only while no toolkit session is connected', function (bool $session): void {
    [$read, $write] = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
    $fired = 0;
    $descriptor = CFFileDescriptor::create(null, streamDescriptor($read), false, function () use (&$fired): void {
        $fired++;
    });
    $descriptor->enableCallBacks(kCFFileDescriptorReadCallBack);
    $runLoopSource = $descriptor->createRunLoopSource(null, 0);
    CFRunLoop::getMain()->addSource($runLoopSource, kCFRunLoopDefaultMode);
    fwrite($write, 'x');
    // CFFileDescriptor signals its run-loop source from its own thread: give it the time to.
    usleep(50_000);
    $source = gcSource(new FakeControllers(), $session);

    $source->poll();
    CFRunLoop::getMain()->removeSource($runLoopSource, kCFRunLoopDefaultMode);
    $descriptor->invalidate();

    expect($fired)->toBe($session ? 0 : 1);
})->with(['no session' => [false], 'a session' => [true]]);

it('reads the macOS version from SystemVersion.plist', function (): void {
    $plist = tempnam(sys_get_temp_dir(), 'plist');
    file_put_contents($plist, "<plist><dict>\n\t<key>ProductName</key>\n\t<string>macOS</string>\n\t<key>ProductVersion</key>\n\t<string>15.4.1</string>\n</dict></plist>");

    try {
        expect(GameControllerPadSource::macosVersion($plist))->toBe('15.4.1')
            ->and(GameControllerPadSource::macosVersion($plist.'.gone'))->toBe('unknown')
            ->and(GameControllerPadSource::macosVersion())->toMatch('/^\d+\.\d+/');
    } finally {
        unlink($plist);
    }
});

it('reads this Mac\'s own controllers as gc- devices, watching and letting go', function (): void {
    $source = (new GameControllerPadSource(inputFrame(), fn (): bool => true))->connect();

    $source->poll();
    $ids = array_keys([...$source->gameControllers(), ...$source->gamePads()]);
    $source->disconnect();

    expect(array_filter($ids, fn (string $id): bool => ! str_starts_with($id, 'gc-')))->toBe([])
        ->and($source->connected())->toBeFalse();
});
