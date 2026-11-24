<?php

declare(strict_types=1);

use Jovian\Toolkits\Appkit\AppkitAdapterDriver;
use Jovian\Toolkits\Appkit\Providers\VenusianAppkitServiceProvider;
use Jovian\Toolkits\Appkit\AppkitSession;
use Jovian\Toolkits\Appkit\AppkitWindow;
use Surface\Core\Providers\TopsideServiceProvider;
use Surface\Toolkits\EventLoop\ToolkitPump;
use Surface\Toolkits\ToolkitAdapters;
use Voyager\Config\Repository;
use Voyager\Core\RenderedInstance;
use Voyager\Vessel\ControlPanel;

/*
| Integration tests: a real framework core with topside and this package's provider, driving
| AppKit itself through the one workflow the package serves: register, connect, open a window,
| present it, resize it, close it. One native application per process, so every test shares one
| core, one driver and one session; each starts with no window open and no mail pending.
*/

if (! extension_loaded('appkit')) {
    throw new RuntimeException('venusian-appkit integration tests need ext-appkit loaded.');
}

pest()->beforeEach(function (): void {
    closeAll();
})->in('Integration');

/**
 * The shared core, made the current container again: a test may build its own.
 */
function toolkitApp(): RenderedInstance
{
    static $app = null;

    if (is_null($app)) {
        $app = bootCore();
    }
    ControlPanel::setInstance($app);

    return $app;
}

/**
 * A booted framework core with topside and this package registered, appkit selected.
 */
function bootCore(array $config = []): RenderedInstance
{
    $app = new RenderedInstance(sys_get_temp_dir());
    $app->registerInstance('config', new Repository(array_replace_recursive([
        'app' => ['name' => 'venusian-appkit-tests'],
        'toolkits' => ['default' => 'appkit'],
    ], $config)));
    $app->register(TopsideServiceProvider::class);
    $app->register(VenusianAppkitServiceProvider::class);
    $app->boot();

    return $app;
}

function driver(): AppkitAdapterDriver
{
    toolkitApp();

    return ToolkitAdapters::driver();
}

function session(): AppkitSession
{
    return driver()->connect();
}

/**
 * Open and present a window, pumping until its content area has the size asked for.
 */
function openWindow(string $name = 'main', int $width = 400, int $height = 300): AppkitWindow
{
    /** @var AppkitWindow $window */
    $window = driver()->open($name, $width, $height);
    $window->present();
    if (! pumpUntil(fn (): bool => $window->size() === [$width, $height])) {
        throw new RuntimeException("Window '{$name}' was not laid out at {$width}x{$height}: ".json_encode($window->size()));
    }

    return $window;
}

/**
 * Close every open window and drop what they posted.
 */
function closeAll(): void
{
    $windows = (fn (): array => $this->windows)->call(driver());
    foreach ($windows as $window) {
        $window->close();
    }
    pumpFor(0.05);
    takeMail();
}

/**
 * Mail the session is holding for a loop, taken out.
 *
 * @return list<object>
 */
function takeMail(): array
{
    return (function (): array {
        [$mail, $this->outbox] = [$this->outbox, []];

        return $mail;
    })->call(session());
}

/**
 * @param list<object> $mail
 * @return list<string>
 */
function mailNames(array $mail): array
{
    return array_map(static fn (object $m): string => (new ReflectionClass($m))->getShortName(), $mail);
}

/**
 * How many of $mail are $type for the window $name.
 *
 * @param list<object> $mail
 */
function countMail(array $mail, string $type, string $name): int
{
    return count(array_filter($mail, fn (object $m): bool => (new ReflectionClass($m))->getShortName() === $type && $m->window === $name));
}

/**
 * Pump, collecting mail, until mail of the short class name $type arrives or $seconds pass.
 *
 * @return list<object> everything collected
 */
function mailUntil(string $type, float $seconds = 2.0): array
{
    $mail = [];
    pumpUntil(function () use (&$mail, $type): bool {
        array_push($mail, ...takeMail());

        return in_array($type, mailNames($mail), true);
    }, $seconds);

    return $mail;
}

/**
 * Pump through the loop's own sleeper, which sends latest-only mail after each pump.
 */
function pumpFor(float $seconds): void
{
    $pump = new ToolkitPump(session());
    $until = microtime(true) + $seconds;
    while (microtime(true) < $until) {
        $pump->sleep(10_000_000);
    }
}

/**
 * Pump until $done() holds or $seconds pass; say whether it held.
 */
function pumpUntil(Closure $done, float $seconds = 2.0): bool
{
    $pump = new ToolkitPump(session());
    $until = microtime(true) + $seconds;
    while (! $done() && microtime(true) < $until) {
        $pump->sleep(10_000_000);
    }

    return $done();
}

/**
 * Pump until the native window is the active one, collecting mail. macOS activation is
 * cooperative: while the user works in another app the system may decline it, and no API
 * forces it. There the test is skipped; elsewhere activation must happen.
 *
 * @return list<object> the mail collected
 */
function requireActive(AppkitWindow $window): array
{
    $mail = [];
    $active = pumpUntil(function () use ($window, &$mail): bool {
        array_push($mail, ...takeMail());

        return nativeActive($window);
    });
    if (! $active) {
        if (PHP_OS_FAMILY === 'Darwin') {
            test()->markTestSkipped('macOS declined to activate the app: another app holds focus.');
        }
        throw new RuntimeException("Window '{$window->name()}' did not become active.");
    }
    pumpFor(0.1);
    array_push($mail, ...takeMail());

    return $mail;
}

/** The user's close, as the window system sends it. */
function nativeUserClose(AppkitWindow $window): void
{
    $window->native()->performClose(null);
}

/** Resize the native window from code, as the window system would. */
function nativeResize(AppkitWindow $window, int $width, int $height): void
{
    $window->native()->setContentSize(new NSSize((float) $width, (float) $height));
}

function nativeTitle(AppkitWindow $window): string
{
    return $window->native()->title();
}

function nativeActive(AppkitWindow $window): bool
{
    return $window->native()->isKeyWindow();
}

/** Whether a native window is still shown: a closed one is not. */
function nativeShown(object $native): bool
{
    return $native->isVisible();
}
