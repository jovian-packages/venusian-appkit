<?php

namespace Venusian\AppKit\Tests\Support;

use Closure;
use Jovian\Venusian\AppKit\Input\AppActivity;

final class FakeAppActivity implements AppActivity
{
    public ?Closure $resigned = null;

    public bool $stopped = false;

    /** Fire the resign callback, as NSApplicationDidResignActiveNotification would. */
    public function resign(): void
    {
        if (! is_null($this->resigned)) {
            ($this->resigned)();
        }
    }

    public function onResign(Closure $resigned): void
    {
        $this->resigned = $resigned;
        $this->stopped = false;
    }

    public function stop(): void
    {
        $this->stopped = true;
        $this->resigned = null;
    }
}
