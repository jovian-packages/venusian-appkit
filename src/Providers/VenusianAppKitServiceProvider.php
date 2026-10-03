<?php

namespace Jovian\Toolkits\Appkit\Providers;

use Jovian\Toolkits\Appkit\Contracts\Bridge\AppkitBridgeDriver;
use ReflectionException;
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
        $this->app->registerSingleton(AppkitBridgeDriver::class, fn (FrameworkCore $app) => $app->get('toolkit-bridge')->driver('appkit'));
    }

    public function boot(): void
    {

    }
}
