<?php

namespace Jovian\Toolkits\Appkit\Windows;

use Jovian\Toolkits\Appkit\Input\AppkitInputEngine;
use NSEvent;
use ObjCKeyWindow;
use Surface\Contracts\Windows\Mail\WindowKeyPressed;
use Surface\Contracts\Windows\Primitives\TKCanvas;
use Surface\HumanInput\Keymaps\MacKeycodes;
use WeakReference;

/**
 * A toolkit window's keys that nothing took: the window's own keyDown:, which AppKit reaches only
 * after the menu's key equivalents and every responder from the first responder up declined the
 * key (a text field's field editor takes every key it is sent). Tab (key code 48, with or without
 * Shift) goes on to NSWindow, which moves along the key-view loop, unless the focused canvas takes
 * Tab; every other key posts WindowKeyPressed and is handled.
 */
final class AppkitWindowKeys
{
    private const int TAB = 48;

    /** @var WeakReference<AppkitWindow> the window holds this */
    private readonly WeakReference $window;

    private bool $attached = true;

    public function __construct(AppkitWindow $window, private readonly ObjCKeyWindow $native)
    {
        $this->window = WeakReference::create($window);
        $self = WeakReference::create($this);
        $native->on('keyDown:', static fn (NSEvent $event): bool => $self->get()?->pressed($event) ?? false);
    }

    /** The window closed: no key posts after. */
    public function detach(): void
    {
        if ($this->attached) {
            $this->attached = false;
            $this->native->off('keyDown:');
        }
    }

    private function pressed(NSEvent $event): bool
    {
        $window = $this->window->get();
        if (! $this->attached || ! $window instanceof AppkitWindow || ! $window->isOpen()) {
            return false;
        }
        if ($event->keyCode() === self::TAB) {
            $focused = $window->focused();

            return $focused instanceof TKCanvas && $focused->takesTab();
        }
        $window->session()->post(new WindowKeyPressed(
            $window->name(),
            MacKeycodes::key($event->keyCode()),
            self::character($event->charactersIgnoringModifiers() ?? ''),
            AppkitInputEngine::modifiers($event->modifierFlags()),
            $event->isARepeat(),
        ));

        return true;
    }

    /** The layout's text for the key: '' for a control character and for AppKit's function-key characters (U+F700–U+F8FF). */
    private static function character(string $characters): string
    {
        if ($characters === '') {
            return '';
        }
        $code = mb_ord($characters, 'UTF-8');

        return $code < 0x20 || $code === 0x7F || ($code >= 0xF700 && $code <= 0xF8FF) ? '' : mb_substr($characters, 0, 1, 'UTF-8');
    }
}
