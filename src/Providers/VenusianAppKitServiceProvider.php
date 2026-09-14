<?php

namespace Jovian\Venusian\AppKit\Providers;

use Jovian\Venusian\AppKit\Sessions\AppKitStageSession;
use Jovian\Venusian\AppKit\Sessions\BridgedMacOSSession;
use Voyager\Contracts\Vessel\Vessel;
use Voyager\NutsAndBolts\ServiceProvider;

/**
 * Publishes the AppKit bridge session and the AppKit stage host under the
 * aliases Surface looks for on macOS.
 */
class VenusianAppKitServiceProvider extends ServiceProvider
{
    /**
     * Bind the bridge session behind 'mac.bridge' and the stage host, which
     * rides that same session, behind 'stage.appkit'.
     *
     * Surface resolves those strings and nothing else, so installing this
     * package is the whole of what makes macOS windowing and AppKit stages
     * available.
     *
     * @return void
     */
    public function register(): void
    {
        $this->app->singleton(BridgedMacOSSession::class);
        $this->app->alias(BridgedMacOSSession::class, 'mac.bridge');

        $this->app->singleton(AppKitStageSession::class, fn (Vessel $app) => new AppKitStageSession($app->make(BridgedMacOSSession::class)));
        $this->app->alias(AppKitStageSession::class, 'stage.appkit');
    }

    /**
     * Nothing to boot. The session initialises AppKit when it is first resolved.
     * @return void
     */
    public function boot(): void {}
}
