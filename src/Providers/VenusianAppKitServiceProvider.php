<?php

namespace Jovian\Venusian\AppKit\Providers;

use Jovian\Venusian\AppKit\Sessions\BridgedMacOSSession;
use Voyager\NutsAndBolts\ServiceProvider;

/**
 * Publishes the AppKit session under the alias Surface looks for on macOS.
 */
class VenusianAppKitServiceProvider extends ServiceProvider
{
    /**
     * Bind the session as a singleton behind 'mac.bridge'.
     *
     * Surface's build action resolves that string and nothing else, so installing
     * this package is the whole of what makes macOS windowing available.
     *
     * @return void
     */
    public function register(): void
    {
        $this->app->singleton(BridgedMacOSSession::class);
        $this->app->alias(BridgedMacOSSession::class, 'mac.bridge');
    }

    /**
     * Nothing to boot. The session initialises AppKit when it is first resolved.
     * @return void
     */
    public function boot(): void {}
}
