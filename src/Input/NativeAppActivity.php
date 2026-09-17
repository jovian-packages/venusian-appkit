<?php

namespace Jovian\Venusian\AppKit\Input;

use Closure;
use Jovian\Bindings\AppKit\Runtime\Bridge;
use Jovian\Bindings\AppKit\Runtime\ObjCObject;

/** NSApplicationDidResignActiveNotification as an AppActivity. */
final class NativeAppActivity implements AppActivity
{
    private ?int $observer = null;

    public function onResign(Closure $resigned): void
    {
        $this->stop();

        $this->observer = Bridge::observeNotification(
            0,
            'NSApplicationDidResignActiveNotification',
            static function (?ObjCObject $object, string $name) use ($resigned): void {
                $resigned();
            },
        );
    }

    public function stop(): void
    {
        if (is_null($this->observer)) {
            return;
        }

        Bridge::removeObserver($this->observer);
        $this->observer = null;
    }
}
