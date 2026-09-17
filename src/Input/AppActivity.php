<?php

namespace Jovian\Venusian\AppKit\Input;

use Closure;

/** Whether the app is still the active one, engine-free. */
interface AppActivity
{
    /** @param Closure(): void $resigned called when the app stops being the active app */
    public function onResign(Closure $resigned): void;

    /** Stop the notification onResign() registered. */
    public function stop(): void;
}
