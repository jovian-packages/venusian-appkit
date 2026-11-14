<?php

namespace Jovian\Toolkits\Appkit\Providers;

use ReflectionException;
use Jovian\Toolkits\Appkit\AppkitBridge;
use Jovian\Toolkits\Appkit\AppkitSession;
use Surface\Toolkits\Toolkits;
use Voyager\Contracts\Core\FrameworkCore;
use Voyager\NutsAndBolts\ServiceProvider;

class VenusianAppkitServiceProvider extends ServiceProvider
{
    /**
     * The AppKit driver is the bridge's: resolving it by its contract asks the toolkit manager,
     * so there is one driver, one session and one set of windows.
     *
     * @return void
     * @throws ReflectionException
     */
    public function register(): void
    {
        $this->app->registerSingleton(AppkitBridge::class, fn (FrameworkCore $app) => Toolkits::bridge('appkit'));
        $this->app->alias('appkit-bridge', AppkitBridge::class);

        // A canvas's native pixels: BGRA8, which Core Animation shows without converting. Surface uses it
        // unless config framebuffers.canvas_format names a format.
        //$this->app->registerSingleton('framebuffers.native_canvas_format', fn (): string => 'bgra8');
    }

    /**
     * The toolkit's driver, registered on the bridge by this package: Surface names no toolkit.
     * With Surface's HumanInput bound too, the 'appkit' input engine and the 'gamecontroller'
     * pad source.
     */
    public function boot(): void
    {
        if (device_os_family() === 'linux') {
            return;                                                   // no AppKit here: the toolkit stays unregistered
        }

        $toolkits = $this->app->get('toolkit-bridge-manager');
        $toolkits->extend('appkit', fn ($app): AppkitBridge => new AppkitBridge($app));

        //if ($this->app->has('human-input')) {
        //    self::input($this->app->get('human-input'), $toolkits);
        //}
    }

    /**
     * The 'appkit' input engine for the AppKit session on $toolkits, and the 'gamecontroller'
     * pad source (human-input.pads.mac). The source runs the main run loop itself, and refuses
     * where macOS keeps pads from a process that cannot become active, only while no toolkit
     * session on $toolkits is connected.
     */
    /*public static function input(HumanInputManager $input, ToolkitManager $toolkits): void
    {
        $input->extend('appkit', fn (InputFrame $frame): AppkitInputEngine => new AppkitInputEngine($frame, $toolkits->driver('appkit')));
        $input->extendPads('gamecontroller', fn (InputFrame $frame): GameControllerPadSource => new GameControllerPadSource(
            $frame,
            fn (): bool => array_any(
                $toolkits->getDrivers(),
                fn (mixed $driver): bool => $driver instanceof ToolkitBridgeDriver && $driver->session()?->connected() === true,
            ),
        ));
    }*/
}
