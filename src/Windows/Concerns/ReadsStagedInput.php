<?php

namespace Jovian\Toolkits\Appkit\Windows\Concerns;

use Jovian\Toolkits\Appkit\Input\AppkitInputEngine;
use Jovian\Toolkits\Appkit\Primitives\AppkitCanvasKeys;
use Jovian\Toolkits\Appkit\Windows\AppkitCanvasPointer;
use Jovian\Toolkits\Appkit\Windows\AppkitRightClicks;
use Jovian\Toolkits\Appkit\Windows\AppkitStagedTap;
use Jovian\Toolkits\Appkit\Windows\AppkitWindowKeys;
use NSEvent;
use NSEventMask;
use NSEventModifierFlags;
use NSEventType;
use NSObject;
use NSPoint;
use NSRect;
use NSTrackingArea;
use NSTrackingAreaOptions;
use ObjCFocusView;
use Surface\Contracts\HumanInput\Key;
use Surface\Contracts\HumanInput\MouseButton;
use Surface\HumanInput\Keymaps\MacKeycodes;
use WeakReference;

/**
 * A staged window's input mail while watchInput() is on, the staged twin of the canvas input.
 *
 * The mouse comes from a session tap (AppkitStagedTap): presses, drags and releases (the button
 * pressed first owns the press, others post nothing until it ends), moves and wheels over the
 * content, and enters and exits from a tracking area on the content view. The keys come from a
 * focus view inside the content view that is the window's first responder: keyDown: posts the key
 * and hands the event to interpretKeyEvents:, whose insertText: posts the committed text; keyUp:
 * posts the release; flagsChanged: posts a lone modifier key's press and release.
 */
trait ReadsStagedInput
{
    private const int TRACKING = NSTrackingAreaOptions::MOUSE_ENTERED_AND_EXITED->value | NSTrackingAreaOptions::MOUSE_MOVED->value
        | NSTrackingAreaOptions::ACTIVE_IN_ACTIVE_APP->value | NSTrackingAreaOptions::IN_VISIBLE_RECT->value;

    private ?AppkitStagedTap $tap = null;

    private ?ObjCFocusView $keys = null;

    private ?NSTrackingArea $area = null;

    private ?NSObject $monitor = null;

    /** The mouse button pressed first and not yet released. */
    private ?MouseButton $held = null;

    /** @var array<int, true> the key codes posted as pressed and not yet released */
    private array $down = [];

    /** @var array<string, int> the modifier keys (Key values) posted as pressed, with their flag bit */
    private array $chord = [];

    public function watchInput(bool $on = true): static
    {
        parent::watchInput($on);
        $on ? $this->listen() : $this->mute();

        return $this;
    }

    /** One event of this window at $point, in window (base) coordinates: what the tap hands on. Tests call it, since an event made from a CGEvent reads window number 0. */
    public function seen(NSEvent $event, NSPoint $point): void
    {
        if (! $this->isOpen() || ! $this->watchesInput()) {
            return;
        }
        match ($event->type()) {
            NSEventType::LEFT_MOUSE_DOWN, NSEventType::RIGHT_MOUSE_DOWN, NSEventType::OTHER_MOUSE_DOWN => $this->mouseDown($event, $point),
            NSEventType::LEFT_MOUSE_DRAGGED, NSEventType::RIGHT_MOUSE_DRAGGED, NSEventType::OTHER_MOUSE_DRAGGED => $this->mouseDragged($event, $point),
            NSEventType::LEFT_MOUSE_UP, NSEventType::RIGHT_MOUSE_UP, NSEventType::OTHER_MOUSE_UP => $this->mouseUp($event, $point),
            NSEventType::MOUSE_MOVED => $this->mouseMoved($event, $point),
            NSEventType::SCROLL_WHEEL => $this->scrollWheel($event, $point),
            NSEventType::MOUSE_ENTERED => $event->trackingArea() === $this->area ? $this->pointerEntered() : null,
            NSEventType::MOUSE_EXITED => $event->trackingArea() === $this->area ? $this->pointerLeft() : null,
            default => null,
        };
    }

    /** keyDown: of the focus view: the key, then the text it composes. */
    public function keyDown(NSEvent $event): bool
    {
        $this->down[$event->keyCode()] = true;
        $this->keyPressed(
            MacKeycodes::key($event->keyCode()),
            AppkitWindowKeys::character($event->charactersIgnoringModifiers() ?? ''),
            AppkitInputEngine::modifiers($event->modifierFlags()),
            $event->isARepeat(),
        );
        $this->keys?->interpretKeyEvents([$event]);

        return true;
    }

    /** keyUp: of the focus view. */
    public function keyUp(NSEvent $event): bool
    {
        $this->releaseKey($event);

        return true;
    }

    /**
     * flagsChanged: of the focus view. A modifier key, by its own flag: set is its press, clear its
     * release. Then every modifier posted as pressed whose flag is gone is released, whichever key
     * the event names (focus that left mid-chord comes back as one change naming key code 0).
     */
    public function flagsChanged(NSEvent $event): bool
    {
        $now = $event->modifierFlags() & AppkitCanvasKeys::DEVICE_INDEPENDENT;
        $modifiers = AppkitInputEngine::modifiers($event->modifierFlags());
        $bit = AppkitCanvasKeys::MODIFIER_FLAGS[$event->keyCode()] ?? 0;
        if ($bit !== 0 && ($now & $bit) !== 0) {
            $key = MacKeycodes::key($event->keyCode());
            $this->chord[$key->value] = $bit;
            $this->keyPressed($key, '', $modifiers, false);
        }
        $released = $bit !== 0 && ($now & $bit) === 0 ? MacKeycodes::key($event->keyCode())->value : null;
        foreach ($this->chord as $value => $flag) {
            if ($value === $released || ($now & $flag) === 0) {
                unset($this->chord[$value]);
                $this->keyReleased(Key::from($value), $modifiers);
            }
        }

        return true;
    }

    /** insertText:replacementRange: of the focus view: text the keys composed and committed. */
    public function inserted(string $text): bool
    {
        $this->textCommitted($text);

        return true;
    }

    /** doCommandBySelector: of the focus view: a key binding's command; a selector not in the table posts nothing. */
    public function commanded(string $selector): bool
    {
        $command = AppkitCanvasKeys::COMMANDS[$selector] ?? null;
        if (! is_null($command)) {
            $this->editCommanded($command);
        }

        return true;
    }

    /** An Edit menu action reached the focus view. */
    public function acted(string $action): bool
    {
        $this->editCommanded(AppkitCanvasKeys::ACTIONS[$action]);

        return true;
    }

    private function listen(): void
    {
        if (! is_null($this->tap) || is_null($this->window)) {
            return;
        }
        $this->tap = new AppkitStagedTap($this, $this->session);
        $this->window->setAcceptsMouseMovedEvents(true);
        $this->area = NSTrackingArea::initWithRectOptionsOwnerUserInfo(new NSRect(0.0, 0.0, 0.0, 0.0), self::TRACKING, $this->view, null);
        $this->view->addTrackingArea($this->area);

        // The view holds the handlers and the window holds the view: a weak reference breaks the cycle.
        $self = WeakReference::create($this);
        $this->keys = ObjCFocusView::initWithFrame(new NSRect(0.0, 0.0, 0.0, 0.0));
        $this->keys->on('acceptsFirstResponder', static fn (): bool => true);
        $this->keys->on('keyDown:', static fn (NSEvent $event): bool => $self->get()?->keyDown($event) ?? false);
        $this->keys->on('keyUp:', static fn (NSEvent $event): bool => $self->get()?->keyUp($event) ?? false);
        $this->keys->on('flagsChanged:', static fn (NSEvent $event): bool => $self->get()?->flagsChanged($event) ?? false);
        $this->keys->on('insertText:replacementRange:', static fn (string $text): bool => $self->get()?->inserted($text) ?? false);
        // interpretKeyEvents: sends the key bindings' selectors here (arrows, delete, ...).
        $this->keys->on('doCommandBySelector:', static fn (string $selector): bool => $self->get()?->commanded($selector) ?? false);
        // The Edit menu's actions: answering them enables the items, and ⌘V reaches the window as paste:.
        foreach (array_keys(AppkitCanvasKeys::ACTIONS) as $action) {
            $this->keys->on($action, static fn (?NSObject $sender): bool => $self->get()?->acted($action) ?? false);
        }
        // NSApplication sends no keyUp: to a window while Command is down; the monitor sees it first.
        $this->monitor = NSEvent::addLocalMonitorForEventsMatchingMaskHandler(
            NSEventMask::KEY_UP->value,
            static function (NSEvent $event) use ($self): NSEvent {
                $self->get()?->commandUp($event);

                return $event;
            },
        );
        $this->view->addSubview($this->keys);
        $this->window->makeFirstResponder($this->keys);
    }

    /** Stops posting and takes the tap, the tracking area, the focus view and the monitor away. */
    private function mute(): void
    {
        $this->tap?->detach();
        $this->tap = null;
        if (! is_null($this->area)) {
            $this->view->removeTrackingArea($this->area);
            $this->area = null;
        }
        if (! is_null($this->monitor)) {
            NSEvent::removeMonitor($this->monitor);
            $this->monitor = null;
        }
        if (! is_null($this->keys)) {
            foreach (['acceptsFirstResponder', 'keyDown:', 'keyUp:', 'flagsChanged:', 'insertText:replacementRange:', 'doCommandBySelector:', ...array_keys(AppkitCanvasKeys::ACTIONS)] as $selector) {
                $this->keys->off($selector);
            }
            if ($this->window?->firstResponder() === $this->keys) {
                $this->window->makeFirstResponder(null);
            }
            $this->keys->removeFromSuperview();
            $this->keys = null;
        }
        [$this->held, $this->down, $this->chord] = [null, [], []];
    }

    /** A key-up with Command down, before NSApplication drops it: the release of a key this window took while its focus view is first responder. */
    private function commandUp(NSEvent $event): void
    {
        if (($event->modifierFlags() & NSEventModifierFlags::COMMAND->value) !== 0
            && $event->window() === $this->window && $this->window?->firstResponder() === $this->keys) {
            $this->releaseKey($event);
        }
    }

    /** KeyReleased for a key whose KeyPressed posted, once. */
    private function releaseKey(NSEvent $event): void
    {
        if (isset($this->down[$event->keyCode()])) {
            unset($this->down[$event->keyCode()]);
            $this->keyReleased(MacKeycodes::key($event->keyCode()), AppkitInputEngine::modifiers($event->modifierFlags()));
        }
    }

    /** @return array{float, float}|null the point in points from the content's top-left; null outside the content */
    private function inContent(NSPoint $point): ?array
    {
        [$x, $y] = AppkitRightClicks::topLeft($this->view, $point);
        $bounds = $this->view->bounds();

        return $x >= 0.0 && $y >= 0.0 && $x <= $bounds->width && $y <= $bounds->height ? [$x, $y] : null;
    }

    private function mouseDown(NSEvent $event, NSPoint $point): void
    {
        $button = AppkitCanvasPointer::button($event);
        $at = $this->inContent($point);
        if (is_null($button) || is_null($at) || ! is_null($this->held)) {
            return;
        }
        $this->held = $button;
        $this->pressed($at[0], $at[1], $button, $event->clickCount(), AppkitInputEngine::modifiers($event->modifierFlags()));
    }

    /** A drag goes on past the content's edge: AppKit sends it to the window the press began in. */
    private function mouseDragged(NSEvent $event, NSPoint $point): void
    {
        if (is_null($this->held)) {
            return;
        }
        [$x, $y] = AppkitRightClicks::topLeft($this->view, $point);
        $this->dragged($x, $y, $this->held, AppkitInputEngine::modifiers($event->modifierFlags()));
    }

    private function mouseUp(NSEvent $event, NSPoint $point): void
    {
        $button = AppkitCanvasPointer::button($event);
        if (is_null($button) || $button !== $this->held) {
            return;
        }
        $this->held = null;
        [$x, $y] = AppkitRightClicks::topLeft($this->view, $point);
        $this->released($x, $y, $button, AppkitInputEngine::modifiers($event->modifierFlags()));
    }

    private function mouseMoved(NSEvent $event, NSPoint $point): void
    {
        $at = $this->inContent($point);
        if (! is_null($at) && is_null($this->held)) {
            $this->pointerMoved($at[0], $at[1], AppkitInputEngine::modifiers($event->modifierFlags()));
        }
    }

    private function scrollWheel(NSEvent $event, NSPoint $point): void
    {
        $phase = AppkitCanvasPointer::phase($event);
        if (is_null($phase) || is_null($this->inContent($point))) {
            return;
        }
        $this->wheeled(
            -$event->scrollingDeltaX(), -$event->scrollingDeltaY(), $event->hasPreciseScrollingDeltas(),
            AppkitInputEngine::modifiers($event->modifierFlags()), $phase,
        );
    }
}
