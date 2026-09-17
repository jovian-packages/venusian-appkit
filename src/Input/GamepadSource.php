<?php

namespace Jovian\Venusian\AppKit\Input;

use Closure;

/** What each attached game controller reads as, engine-free. */
interface GamepadSource
{
    /** @return list<int> the controller handles attached now */
    public function handles(): array;

    public function name(int $controller): string;

    /** Whether the controller has an extended profile (sticks and triggers). */
    public function extended(int $controller): bool;

    /** @return array<string, bool> keyed by GamepadButton->value; absent buttons are left out */
    public function buttons(int $controller): array;

    /** @return array<string, float> keyed by GamepadAxis->value; sticks y-down */
    public function axes(int $controller): array;

    /** @param Closure(): void $changed called when a controller connects or disconnects */
    public function onChange(Closure $changed): void;

    /** Stop the notifications onChange() registered and drop every held controller. */
    public function stop(): void;
}
