<?php

namespace Venusian\AppKit\Tests\Support;

use Closure;
use Jovian\Venusian\AppKit\Input\GamepadSource;

final class FakeGamepadSource implements GamepadSource
{
    /** @var array<int, array{name: string, extended: bool, buttons: array<string, bool>, axes: array<string, float>}> */
    public array $pads = [];

    public ?Closure $changed = null;

    public bool $stopped = false;

    /**
     * Attach a pad and fire the change callback, as a connect notification would.
     *
     * @param array<string, bool> $buttons
     * @param array<string, float> $axes
     */
    public function plug(int $handle, string $name, bool $extended, array $buttons, array $axes = []): void
    {
        $this->pads[$handle] = ['name' => $name, 'extended' => $extended, 'buttons' => $buttons, 'axes' => $axes];
        $this->fire();
    }

    public function unplug(int $handle): void
    {
        unset($this->pads[$handle]);
        $this->fire();
    }

    public function fire(): void
    {
        if (! is_null($this->changed)) {
            ($this->changed)();
        }
    }

    public function handles(): array
    {
        return array_keys($this->pads);
    }

    public function name(int $controller): string
    {
        return $this->pads[$controller]['name'];
    }

    public function extended(int $controller): bool
    {
        return $this->pads[$controller]['extended'];
    }

    public function buttons(int $controller): array
    {
        return $this->pads[$controller]['buttons'];
    }

    public function axes(int $controller): array
    {
        return $this->pads[$controller]['axes'];
    }

    public function onChange(Closure $changed): void
    {
        $this->changed = $changed;
    }

    public function stop(): void
    {
        $this->stopped = true;
        $this->changed = null;
    }
}
