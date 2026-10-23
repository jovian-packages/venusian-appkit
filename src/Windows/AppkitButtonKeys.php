<?php

namespace Jovian\Toolkits\Appkit\Windows;

use Jovian\Toolkits\Appkit\Bridge\AppkitSession;
use Jovian\Toolkits\Appkit\Primitives\AppkitTextArea;
use NSButton;
use NSEvent;
use NSEventModifierFlags;
use NSEventType;
use Surface\Contracts\HumanInput\InputTap;
use Surface\Contracts\Windows\Primitives\ButtonRole;
use Surface\Windows\Primitives\TKButton;
use WeakReference;

/**
 * A toolkit window's default and cancel buttons on the keyboard, from the events the session
 * pumps (a tap, as AppkitRightClicks): Return (36) or keypad Enter (76) clicks the default
 * holder, Esc (53) the cancel holder, with no Command, Control, Option or Shift. The session does
 * not dispatch a key that clicked, so the focused view never sees it. Return goes on to a focused
 * text area, and any key goes on when its holder cannot take it: disabled, hidden, or in a tab not
 * selected (out of the window). Esc in a child with no cancel button is the user's close.
 * A menu that tracks runs its own loop, so its keys never reach the tap.
 */
final class AppkitButtonKeys implements InputTap
{
    private const int RETURN = 36;

    private const int ENTER = 76;

    private const int ESCAPE = 53;

    private bool $watching = true;

    /** @var WeakReference<AppkitWindow> the window holds this */
    private readonly WeakReference $window;

    public function __construct(
        AppkitWindow $window,
        private readonly AppkitSession $session,
    ) {
        $this->window = WeakReference::create($window);
        $session->tap($this);
    }

    public function watching(): bool
    {
        return $this->watching;
    }

    /** The window closed: stop seeing the session's events. */
    public function detach(): void
    {
        $this->session->untap($this);
        $this->watching = false;
    }

    public function see(object $native_event): void
    {
        $window = $this->window->get();
        if (is_null($window) || ! $window->isOpen()) {
            $this->detach();

            return;
        }
        if (! $native_event instanceof NSEvent
            || $native_event->type() !== NSEventType::KEY_DOWN
            || $native_event->windowNumber() !== $window->native()->windowNumber()) {
            return;
        }
        $modifiers = NSEventModifierFlags::COMMAND->value | NSEventModifierFlags::CONTROL->value
            | NSEventModifierFlags::OPTION->value | NSEventModifierFlags::SHIFT->value;
        if (($native_event->modifierFlags() & $modifiers) !== 0) {
            return;
        }
        $role = match ($native_event->keyCode()) {
            self::RETURN, self::ENTER => ButtonRole::DEFAULT,
            self::ESCAPE => ButtonRole::CANCEL,
            default => null,
        };
        if (is_null($role) || ($role === ButtonRole::DEFAULT && $this->textAreaFocused($window))) {
            return;
        }

        $holder = TKButton::holder($window, $role);
        if (! is_null($holder)) {
            $button = $holder->native();
            if ($button instanceof NSButton && $this->canTake($window, $button)) {
                $this->session->withhold($native_event);
                $button->performClick(null);
            }

            return;
        }
        if ($role === ButtonRole::CANCEL && ! is_null($window->parent())) {
            $this->session->withhold($native_event);
            $window->userClose();
        }
    }

    /** The first responder is the text view of a text area in the window. */
    private function textAreaFocused(AppkitWindow $window): bool
    {
        $responder = $window->native()->firstResponder();
        foreach ($window->registry()->all() as $primitive) {
            if ($primitive instanceof AppkitTextArea && $primitive->textView() === $responder) {
                return true;
            }
        }

        return false;
    }

    /** Shows on the screen and is enabled: a tab that is not selected takes its views out of the window. */
    private function canTake(AppkitWindow $window, NSButton $button): bool
    {
        return $button->isEnabled()
            && $button->window() === $window->native()
            && ! $button->isHiddenOrHasHiddenAncestor();
    }
}
