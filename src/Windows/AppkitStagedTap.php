<?php

namespace Jovian\Toolkits\Appkit\Windows;

use NSEvent;
use Surface\Bridge\BridgedToolkitSession;
use Surface\Contracts\HumanInput\InputTap;
use WeakReference;

/**
 * A staged window's mouse, as a session tap: every event the session pumps for the window's own
 * number goes to AppkitStagedWindow::seen(). The window holds this and this holds the window
 * weakly, so a closed window is not kept alive.
 */
final class AppkitStagedTap implements InputTap
{
    private readonly WeakReference $window;

    private bool $attached = true;

    public function __construct(AppkitStagedWindow $window, private readonly BridgedToolkitSession $session)
    {
        $this->window = WeakReference::create($window);
        $session->tap($this);
    }

    public function detach(): void
    {
        if ($this->attached) {
            $this->attached = false;
            $this->session->untap($this);
        }
    }

    public function see(object $native_event): void
    {
        $window = $this->window->get();
        if (is_null($window) || ! $window->isOpen()) {
            $this->detach();

            return;
        }
        if ($native_event instanceof NSEvent && $native_event->windowNumber() === $window->native()->windowNumber()) {
            $window->seen($native_event, $native_event->locationInWindow());
        }
    }
}
