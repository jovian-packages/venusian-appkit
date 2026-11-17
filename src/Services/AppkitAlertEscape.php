<?php

namespace Jovian\Toolkits\Appkit\Services;

use Closure;
use Jovian\Toolkits\Appkit\AppkitSession;
use NSEvent;
use NSEventType;
use NSWindow;

/**
 * Esc at an alert whose default button is also its cancel button. That button's key equivalent
 * is Return, and a button holds one key equivalent, so this answers Esc (key code 53) at the
 * alert's window itself: the session's pump withholds the key down from AppKit and ends the sheet.
 */
final class AppkitAlertEscape
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
        // The session holds the filter, and the dialogs this: a weak reference keeps it from holding either.
        $self = \WeakReference::create($this);
        $session->withholdWhere($this, static fn (NSEvent $event): bool => $self->get()?->escapes($event) ?? false);
    }

    public function watching(): bool
    {
        return $this->watching;
    }

    /** The alert ended: the pump stops asking. */
    public function detach(): void
    {
        $this->session->withholdWhere($this, null);
        $this->watching = false;
    }

    /**
     * Whether $event is Esc pressed at the alert: then the sheet ends with the cancel response and
     * AppKit does not get the key.
     *
     * @internal the pump's filter
     */
    public function escapes(NSEvent $event): bool
    {
        if (! $this->watching
            || $event->type() !== NSEventType::KEY_DOWN
            || $event->windowNumber() !== $this->number
            || $event->keyCode() !== self::ESCAPE) {
            return false;
        }
        ($this->escape)();

        return true;
    }
}
