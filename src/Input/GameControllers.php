<?php

namespace Jovian\Toolkits\Appkit\Input;

use Closure;

/** The game controllers the pad source reads: GameController.framework's, or a test's. */
interface GameControllers
{
    /** @return list<GcReading> every controller listed now, read */
    public function read(): array;

    /**
     * Start listening: wireless discovery, $connected on each controller connect notification,
     * $resigned when the application stops being active.
     *
     * @param Closure(): void $connected
     * @param Closure(): void $resigned
     */
    public function watch(Closure $connected, Closure $resigned): void;

    /** Stop what watch() started. */
    public function unwatch(): void;

    /** Ask for controller input while the app is not active; whether macOS granted it, read back. */
    public function monitorBackground(): bool;

    /** Whether the application is the active one now. */
    public function active(): bool;
}
