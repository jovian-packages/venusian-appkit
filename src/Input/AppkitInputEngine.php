<?php

namespace Jovian\Toolkits\Appkit\Input;

use CGEventSource;
use CGEventSourceStateID;
use Closure;
use Jovian\Toolkits\Appkit\Bridge\AppkitBridgeDriver;
use Jovian\Toolkits\Appkit\Bridge\AppkitSession;
use NSEvent;
use NSEventModifierFlags;
use NSNotificationCenter;
use NSObject;
use NSPoint;
use NSWindow;
use Surface\Contracts\HumanInput\HumanInputException;
use Surface\Contracts\HumanInput\InputEngineDriver;
use Surface\Contracts\HumanInput\InputTap;
use Surface\Contracts\HumanInput\Key;
use Surface\Contracts\HumanInput\Modifiers;
use Surface\Contracts\HumanInput\MouseButton;
use Surface\HumanInput\Devices\Keyboard;
use Surface\HumanInput\Devices\Mouse;
use Surface\HumanInput\InputFrame;
use Surface\HumanInput\Keymaps\MacKeycodes;

/**
 * HumanInput's appkit engine: keyboard and mouse of the AppKit windows this package opened,
 * toolkit windows and staged windows. A tap on AppkitSession: see() copies each event as the
 * session pumps it, before AppKit sends it (so a key-up AppKit keeps from the window while
 * Command is held still counts); poll() applies the copies.
 *
 * Keys and flags count when the event's window is one of ours; buttons and motion when that
 * window or the one under the pointer is; the wheel when the window under the pointer is. The
 * pointer's window is the one of ours under it on screen, so a window that is not key reads
 * right; between events poll() follows the pointer location. Keys read by position through
 * MacKeycodes and the wheel as SDL reads it on macOS, so an appkit sketch and an sdl3 sketch
 * read alike. The application resigning active releases every key and button.
 *
 * AppKit's own tracking loops (a button or slider being clicked, a context menu open) dequeue
 * the events they track, so the session never shows their releases: each poll releases every
 * button and key held here that the system no longer holds (NSEvent::pressedMouseButtons(),
 * CGEventSourceKeyState). A key or modifier let go over another of the app's windows (an About
 * or save panel made key) is released by its own event.
 */
final class AppkitInputEngine implements InputEngineDriver, InputTap
{
    /** Device-dependent modifier bits (IOLLEvent.h NX_DEVICE*KEYMASK; fn: NSEventModifierFlagFunction) by key code: the side a FLAGS_CHANGED changed. */
    private const array SIDES = [
        0x38 => 0x00000002, 0x3C => 0x00000004, // left and right shift
        0x3B => 0x00000001, 0x3E => 0x00002000, // left and right control
        0x3A => 0x00000020, 0x3D => 0x00000040, // left and right option
        0x37 => 0x00000008, 0x36 => 0x00000010, // left and right command
        0x3F => 0x00800000,                     // fn
    ];

    private const int CAPS_LOCK = 0x39;

    /** NSEvent::pressedMouseButtons() bits by button. */
    private const array BUTTON_BITS = ['left' => 0, 'right' => 1, 'middle' => 2, 'x1' => 3, 'x2' => 4];

    /** Each modifier's keys, both sides. */
    private const array MODIFIER_KEYS = [
        'shift' => [Key::LEFT_SHIFT, Key::RIGHT_SHIFT],
        'ctrl' => [Key::LEFT_CTRL, Key::RIGHT_CTRL],
        'alt' => [Key::LEFT_ALT, Key::RIGHT_ALT],
        'meta' => [Key::LEFT_META, Key::RIGHT_META],
    ];

    private bool $connected = false;

    private readonly Keyboard $keyboard;

    private readonly Mouse $mouse;

    private ?AppkitSession $session = null;

    /** @var list<SeenEvent> copied since the last poll */
    private array $seen = [];

    /** @var array<int, NSWindow> by window number: the windows moved events were turned on for */
    private array $tracking = [];

    /** The resign-active observer, while connected. */
    private ?NSObject $observer = null;

    private bool $resigned = false;

    /** Where the pointer location was at the last poll. */
    private ?NSPoint $pointer = null;

    /** Where the pointer last was in a window of ours, kept when it leaves. */
    private float $x = 0.0;

    private float $y = 0.0;

    /** @var array<int, Key> keys held here, by key code */
    private array $held = [];

    /** @var Closure(): NSPoint */
    private readonly Closure $pointer_at;

    /** @var Closure(): int */
    private readonly Closure $buttons_down;

    /** @var Closure(int): bool */
    private readonly Closure $key_down;

    /**
     * @param (Closure(): NSPoint)|null $pointer the pointer's screen location (origin bottom left of the main screen); NSEvent::mouseLocation() by default
     * @param (Closure(): int)|null $buttons the mouse buttons down now, as a bit mask; NSEvent::pressedMouseButtons() by default
     * @param (Closure(int): bool)|null $keys whether the key with a macOS key code is down now; CGEventSourceKeyState over the session's combined state by default
     */
    public function __construct(InputFrame $frame, private readonly AppkitBridgeDriver $driver, ?Closure $pointer = null, ?Closure $buttons = null, ?Closure $keys = null)
    {
        $this->keyboard = new Keyboard($frame);
        $this->mouse = new Mouse($frame);
        $this->pointer_at = $pointer ?? static fn (): NSPoint => NSEvent::mouseLocation();
        $this->buttons_down = $buttons ?? static fn (): int => NSEvent::pressedMouseButtons();
        $this->key_down = $keys ?? static fn (int $code): bool => CGEventSource::keyState(CGEventSourceStateID::COMBINED_SESSION_STATE, $code);
    }

    /** @throws HumanInputException The driver has no connected session to tap. */
    public function connect(): static
    {
        if ($this->connected) {
            return $this;
        }

        $session = $this->driver->session();
        if (! $session instanceof AppkitSession || ! $session->connected()) {
            throw new HumanInputException('appkit: no connected AppKit session to read input from; HumanInput starts this engine when the bridge connects one.');
        }

        $this->observer = NSNotificationCenter::defaultCenter()->addObserverForNameObjectQueueUsingBlock(
            NSApplicationDidResignActiveNotification, null, null, function (): void {
                $this->resigned = true;
            },
        );
        $session->tap($this);
        [$this->session, $this->connected] = [$session, true];

        return $this;
    }

    public function disconnect(): void
    {
        if (! $this->connected) {
            return;
        }

        $this->session?->untap($this);
        if (! is_null($this->observer)) {
            NSNotificationCenter::defaultCenter()->removeObserver($this->observer);
        }
        foreach (array_intersect_key($this->tracking, $this->windows()) as $native) {
            $native->setAcceptsMouseMovedEvents(false);
        }
        [$this->session, $this->observer, $this->tracking, $this->seen, $this->resigned, $this->pointer, $this->held] = [null, null, [], [], false, null, []];
        $this->connected = false;
    }

    public function connected(): bool
    {
        return $this->connected;
    }

    public function see(object $native_event): void
    {
        if ($native_event instanceof NSEvent && ! is_null($seen = SeenEvent::from($native_event))) {
            $this->seen[] = $seen;
        }
    }

    public function settle(): void
    {
        $this->keyboard->settle();
        $this->mouse->settle();
    }

    public function poll(): void
    {
        if (! $this->connected) {
            return;
        }

        $windows = $this->windows();
        $this->track($windows);
        $this->follow($windows);

        [$seen, $this->seen] = [$this->seen, []];
        foreach ($seen as $event) {
            match ($event->kind) {
                SeenKind::Key => (isset($windows[$event->window]) || (! $event->down && isset($this->held[$event->code]))) && $this->key($event),
                SeenKind::Flags => (isset($windows[$event->window]) || $this->releases($event)) && $this->flags($event),
                SeenKind::Button => $this->button($event, $windows),
                SeenKind::Motion => $this->motion($event, $windows),
                SeenKind::Wheel => $this->wheel($event, $windows),
            };
        }

        $this->reconcile();

        if ($this->resigned) {
            $this->resigned = false;
            $this->held = [];
            $this->keyboard->releaseAll();
            $this->mouse->releaseAll();
        }
    }

    /** Release what is held here and no longer held by the system: the releases a tracking loop dequeued itself. */
    private function reconcile(): void
    {
        $buttons = ($this->buttons_down)();
        foreach (MouseButton::cases() as $button) {
            if ($this->mouse->isDown($button) && ($buttons & (1 << self::BUTTON_BITS[$button->value])) === 0) {
                $this->mouse->update($button, false);
            }
        }

        $released = false;
        foreach ($this->held as $code => $key) {
            if (! ($this->key_down)($code)) {
                $this->keyboard->update($key, false);
                unset($this->held[$code]);
                $released = true;
            }
        }
        if ($released) {
            $now = $this->keyboard->modifiers();
            $holds = fn (string $modifier): bool => array_any(self::MODIFIER_KEYS[$modifier], fn (Key $key): bool => $this->keyboard->isDown($key));
            $this->keyboard->setModifiers(new Modifiers(
                shift: $now->shift && $holds('shift'),
                ctrl: $now->ctrl && $holds('ctrl'),
                alt: $now->alt && $holds('alt'),
                meta: $now->meta && $holds('meta'),
            ));
        }
    }

    /** Whether a flags change from a window not ours lets go of a modifier held here. */
    private function releases(SeenEvent $event): bool
    {
        return isset(self::SIDES[$event->code], $this->held[$event->code]) && ($event->flags & self::SIDES[$event->code]) === 0;
    }

    public function keyboard(): Keyboard
    {
        return $this->keyboard;
    }

    public function mouse(): Mouse
    {
        return $this->mouse;
    }

    /** What a key down types: nothing while Command or Control is held (a shortcut), and no control characters or the function keys' private-use characters (U+F700–U+F8FF). */
    public static function text(string $characters, int $flags): string
    {
        if (($flags & (NSEventModifierFlags::COMMAND->value | NSEventModifierFlags::CONTROL->value)) !== 0) {
            return '';
        }

        return (string) preg_replace('/[\x{0}-\x{1F}\x{7F}\x{F700}-\x{F8FF}]/u', '', $characters);
    }

    /**
     * A scroll as SDL reads it on macOS, so both engines agree: deltaX negated; a mouse's ticks
     * (no precise deltas) rounded away from zero; a natural ("inverted") scroll turned back, so
     * dy > 0 is always rolled away from the user.
     *
     * @return array{float, float}
     */
    public static function wheelDelta(float $deltaX, float $deltaY, bool $precise, bool $inverted): array
    {
        [$x, $y] = [-$deltaX, $deltaY];
        if (! $precise) {
            [$x, $y] = [self::tick($x), self::tick($y)];
        }

        return $inverted ? [-$x, -$y] : [$x, $y];
    }

    private static function tick(float $value): float
    {
        return $value > 0 ? ceil($value) : ($value < 0 ? floor($value) : 0.0);
    }

    /** @return array<int, array{string, NSWindow}> name and native window, by window number */
    private function windows(): array
    {
        $windows = [];
        foreach ([...array_values($this->driver->all()), ...array_values($this->driver->allStaged())] as $window) {
            $native = $window->native();
            $windows[$native->windowNumber()] = [$window->name(), $native];
        }

        return $windows;
    }

    /** @param array<int, array{string, NSWindow}> $windows */
    private function track(array $windows): void
    {
        $this->tracking = array_intersect_key($this->tracking, $windows);
        foreach ($windows as $number => [, $native]) {
            if (! isset($this->tracking[$number])) {
                $native->setAcceptsMouseMovedEvents(true);
                $this->tracking[$number] = $native;
            }
        }
    }

    /**
     * The pointer between events: wherever the pointer location moved since the last poll.
     *
     * @param array<int, array{string, NSWindow}> $windows
     */
    private function follow(array $windows): void
    {
        $at = ($this->pointer_at)();
        if (! is_null($this->pointer) && $at->x === $this->pointer->x && $at->y === $this->pointer->y) {
            return;
        }
        $this->pointer = $at;
        $this->place($at->x, $at->y, $windows);
    }

    /**
     * Point the mouse at screen point ($sx, $sy): into the window of ours under it, from its
     * content's top left; under none, nowhere, keeping the last position.
     *
     * @param array<int, array{string, NSWindow}> $windows
     * @return string|null the window's name
     */
    private function place(float $sx, float $sy, array $windows): ?string
    {
        $under = $windows[NSWindow::windowNumberAtPointBelowWindowWithWindowNumber(new NSPoint($sx, $sy), 0)] ?? null;
        if (is_null($under)) {
            if (! is_null($this->mouse->window())) {
                $this->mouse->setPosition($this->x, $this->y, null);
            }

            return null;
        }

        [$name, $native] = $under;
        $content = $native->contentRectForFrameRect($native->frame());
        [$this->x, $this->y] = [$sx - $content->x, $content->y + $content->height - $sy];
        $this->mouse->setPosition($this->x, $this->y, $name);

        return $name;
    }

    private function key(SeenEvent $event): bool
    {
        $this->keyboard->setModifiers(self::modifiers($event->flags));
        if ($event->down) {
            $this->keyboard->appendText(self::text($event->text, $event->flags));
        }
        if (! $event->repeat) {
            $this->hold($event->code, $event->down);
        }

        return true;
    }

    private function flags(SeenEvent $event): bool
    {
        $this->keyboard->setModifiers(self::modifiers($event->flags));
        if ($event->code === self::CAPS_LOCK) {
            // Caps Lock reports its lock toggling, not a key held: each change is one press.
            $this->keyboard->update(Key::CAPS_LOCK, true)->update(Key::CAPS_LOCK, false);
        } elseif (isset(self::SIDES[$event->code])) {
            $this->hold($event->code, ($event->flags & self::SIDES[$event->code]) !== 0);
        }

        return true;
    }

    private function hold(int $code, bool $down): void
    {
        $key = MacKeycodes::key($code);
        $this->keyboard->update($key, $down);
        if ($down) {
            $this->held[$code] = $key;
        } else {
            unset($this->held[$code]);
        }
    }

    /**
     * A click in a window of ours, or let go over one: a drag released outside the window it began
     * in still counts, and a Quartz-made event (window number 0) counts by where it is.
     *
     * @param array<int, array{string, NSWindow}> $windows
     */
    private function button(SeenEvent $event, array $windows): bool
    {
        $over = $this->place($event->x, $event->y, $windows);
        if (! is_null($over) || isset($windows[$event->window])) {
            $this->mouse->update($event->button, $event->down);
        }

        return true;
    }

    /** @param array<int, array{string, NSWindow}> $windows */
    private function motion(SeenEvent $event, array $windows): bool
    {
        $over = $this->place($event->x, $event->y, $windows);
        if (! is_null($over) || isset($windows[$event->window])) {
            $this->mouse->addMotion($event->dx, $event->dy);
        }

        return true;
    }

    /** @param array<int, array{string, NSWindow}> $windows */
    private function wheel(SeenEvent $event, array $windows): bool
    {
        if (! is_null($this->place($event->x, $event->y, $windows))) {
            $this->mouse->addWheel(...self::wheelDelta($event->dx, $event->dy, $event->precise, $event->inverted));
        }

        return true;
    }

    public static function modifiers(int $flags): Modifiers
    {
        return new Modifiers(
            shift: ($flags & NSEventModifierFlags::SHIFT->value) !== 0,
            ctrl: ($flags & NSEventModifierFlags::CONTROL->value) !== 0,
            alt: ($flags & NSEventModifierFlags::OPTION->value) !== 0,
            meta: ($flags & NSEventModifierFlags::COMMAND->value) !== 0,
        );
    }
}
