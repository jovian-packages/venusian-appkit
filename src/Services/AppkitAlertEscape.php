<?php

namespace Jovian\Toolkits\Appkit\Services;

use Closure;
use Jovian\Toolkits\Appkit\Bridge\AppkitSession;
use NSEvent;
use NSEventType;
use NSWindow;
use Surface\Contracts\HumanInput\InputTap;

/**
 * Esc at an alert whose default button is also its cancel button. That button's key equivalent
 * is Return, and a button holds one key equivalent, so this tap, on the session, answers Esc
 * (key code 53) at the alert's window and keeps the event from AppKit.
 */
final class AppkitAlertEscape implements InputTap
{
    private const int ESCAPE = 53;

    private bool $watching = true;

    private readonly int $number;

    /** @param Closure(): void $escape ends the sheet with the cancel button's response */
    public function __construct(
        private readonly AppkitSession $session,
        NSWindow $alert,
        private readonly Closure $escape,
    ) {
        $this->number = $alert->windowNumber();
        $session->tap($this);
    }

    public function watching(): bool
    {
        return $this->watching;
    }

    /** The alert ended: stop seeing the session's events. */
    public function detach(): void
    {
        $this->session->untap($this);
        $this->watching = false;
    }

    public function see(object $native_event): void
    {
        if (! $native_event instanceof NSEvent
            || $native_event->type() !== NSEventType::KEY_DOWN
            || $native_event->windowNumber() !== $this->number
            || $native_event->keyCode() !== self::ESCAPE) {
            return;
        }
        $this->session->withhold($native_event);
        ($this->escape)();
    }
}
