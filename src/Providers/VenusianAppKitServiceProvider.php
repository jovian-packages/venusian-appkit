<?php

namespace Jovian\Venusian\AppKit\Providers;

use Jovian\Venusian\AppKit\Input\AppKitInputEngine;
use Jovian\Venusian\AppKit\Input\ExtInputTap;
use Jovian\Venusian\AppKit\Input\GameControllerSource;
use Jovian\Venusian\AppKit\Input\NativeAppActivity;
use Jovian\Venusian\AppKit\Input\NativeWindowSpace;
use Jovian\Venusian\AppKit\Sessions\AppKitStageSession;
use Jovian\Venusian\AppKit\Sessions\BridgedMacOSSession;
use Voyager\Contracts\Vessel\Vessel;
use Voyager\NutsAndBolts\ServiceProvider;

/**
 * Publishes the AppKit bridge session, the AppKit stage host and the AppKit
 * input engine under the aliases Surface looks for on macOS.
 */
class VenusianAppKitServiceProvider extends ServiceProvider
{
    /**
     * Bind the bridge session behind 'mac.bridge', the stage host, which
     * rides that same session, behind 'stage.appkit', and the input engine,
     * which reads what that session's pump taps, behind 'input.appkit'.
     *
     * Surface resolves those strings and nothing else, so installing this
     * package is the whole of what makes macOS windowing, AppKit stages and
     * AppKit input available.
     *
     * @return void
     */
    public function register(): void
    {
        $this->app->singleton(BridgedMacOSSession::class);
        $this->app->alias(BridgedMacOSSession::class, 'mac.bridge');

        $this->app->singleton(AppKitStageSession::class, fn (Vessel $app) => new AppKitStageSession($app->make(BridgedMacOSSession::class)));
        $this->app->alias(AppKitStageSession::class, 'stage.appkit');

        $this->app->singleton(AppKitInputEngine::class, fn () => new AppKitInputEngine(new ExtInputTap(), new GameControllerSource(), new NativeWindowSpace(), new NativeAppActivity()));
        $this->app->alias(AppKitInputEngine::class, 'input.appkit');
    }

    /**
     * Nothing to boot. The session initialises AppKit when it is first resolved.
     * @return void
     */
    public function boot(): void {}
}
