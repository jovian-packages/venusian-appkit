<?php

namespace Jovian\Toolkits\Appkit\Input;

use Closure;
use GCController;
use NSApplication;
use NSNotificationCenter;
use NSObject;

/** GameController.framework's controllers, watched through the default notification centre. */
final class SystemGameControllers implements GameControllers
{
    /** @var list<NSObject> observer tokens, while watching */
    private array $observers = [];

    private bool $discovering = false;

    public function read(): array
    {
        return array_values(array_filter(array_map(GcReading::of(...), GCController::controllers())));
    }

    public function watch(Closure $connected, Closure $resigned): void
    {
        $this->unwatch();
        $center = NSNotificationCenter::defaultCenter();
        $this->observers = [
            $center->addObserverForNameObjectQueueUsingBlock(GCControllerDidConnectNotification, null, null, fn () => $connected()),
            $center->addObserverForNameObjectQueueUsingBlock(NSApplicationDidResignActiveNotification, null, null, fn () => $resigned()),
        ];
        // A pad put in pairing mode while the process runs connects; paired pads list without it.
        GCController::startWirelessControllerDiscoveryWithCompletionHandler(null);
        $this->discovering = true;
    }

    public function unwatch(): void
    {
        foreach ($this->observers as $observer) {
            NSNotificationCenter::defaultCenter()->removeObserver($observer);
        }
        $this->observers = [];
        if ($this->discovering) {
            GCController::stopWirelessControllerDiscovery();
            $this->discovering = false;
        }
    }

    public function monitorBackground(): bool
    {
        GCController::setShouldMonitorBackgroundEvents(true);

        return GCController::shouldMonitorBackgroundEvents();
    }

    public function active(): bool
    {
        return NSApplication::sharedApplication()->isActive();
    }
}
