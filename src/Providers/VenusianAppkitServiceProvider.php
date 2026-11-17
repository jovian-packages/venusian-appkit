<?php

namespace Jovian\Toolkits\Appkit\Providers;

use ReflectionException;
use Jovian\Toolkits\Appkit\AppkitBridge;
use Jovian\Toolkits\Appkit\AppkitSession;
use Jovian\Toolkits\Appkit\Input\AppkitInputBackend;
use Jovian\Toolkits\Appkit\Input\GameControllerPadSource;
use Surface\HumanInput\HumanInput;
use Surface\HumanInput\HumanInputManager;
use Surface\Toolkits\ToolkitBridge;
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
     * The toolkit's driver, registered on the bridge by this package: topside names no toolkit.
     * With human input on, the 'appkit' input backend and the 'gamecontroller' pad source.
     */
    public function boot(): void
    {
        if (device_os_family() === 'linux') {
            return;                                                   // no AppKit here: the toolkit stays unregistered
        }

        $toolkits = $this->app->get('toolkit-bridge-manager');
        $toolkits->extend('appkit', fn ($app): AppkitBridge => new AppkitBridge($app));

        if (HumanInput::enabled()) {
            self::input(HumanInput::manager());
        }
    }

    /**
     * The 'appkit' input backend and the 'gamecontroller' pad source, when the application turned
     * human input on. The pad source runs the main run loop itself, and refuses where macOS keeps
     * pads from a process that cannot become active, only while no toolkit session is connected.
     */
    public static function input(HumanInputManager $input): void
    {
        $input->extend('appkit', fn (): AppkitInputBackend => new AppkitInputBackend());
        $input->extendPads('gamecontroller', fn (): GameControllerPadSource => new GameControllerPadSource(
            static fn (): bool => array_any(
                Toolkits::bridges(),
                static fn (mixed $bridge): bool => $bridge instanceof ToolkitBridge && $bridge->session()?->connected() === true,
            ),
        ));
    }
}
