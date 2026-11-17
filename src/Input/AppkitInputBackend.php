<?php

namespace Jovian\Toolkits\Appkit\Input;

use CGEventSource;
use CGEventSourceStateID;
use Closure;
use Jovian\Toolkits\Appkit\Primitives\AppkitCanvas;
use NSEvent;
use NSEventMask;
use NSEventModifierFlags;
use NSEventPhase;
use NSEventType;
use NSNotificationCenter;
use NSObject;
use NSPoint;
use NSWindow;
use Surface\Contracts\Graphics\ActorStage;
use Surface\Contracts\HumanInput\HumanInputException;
use Surface\Contracts\HumanInput\InputContext;
use Surface\Contracts\HumanInput\Key;
use Surface\Contracts\HumanInput\Modifiers;
use Surface\Contracts\HumanInput\MouseButton;
use Surface\Contracts\HumanInput\ScrollPhase;
use Surface\HumanInput\InputBackend;
use Surface\HumanInput\InputChannel;
use Surface\HumanInput\Keymaps\MacKeycodes;
use Throwable;
use WeakMap;

/**
 * The 'appkit' input backend: the keyboard and pointer of AppKit canvases, for applications whose
 * toolkit is AppKit on macOS.
 *
 * Keys go to the canvas that is first responder: clicking a canvas whose input is open makes it
 * so, as does focus() from code. Its view takes keyDown:, keyUp: and flagsChanged: and hands key
 * downs to interpretKeyEvents:, so typed text comes from insertText: with dead keys and input
 * methods composed. AppKit sends no keyUp: while Command is down: a local monitor catches those.
 * A canvas that stops being first responder, or whose window stops being key, lets go of its keys.
 *
 * The pointer goes to the canvas under it, read by a local monitor as the session dispatches each
 * event (the monitor sees it and passes it on unchanged): moves, buttons, drags, the wheel, pinch,
 * rotation and force. A button pressed on a canvas is captured by it: its drags and its release go
 * to that canvas wherever the pointer is. A canvas the pointer leaves is told so.
 *
 * Positions are the canvas's pixels from its top left. Scrolling follows the contract: dy > 0
 * shows content further down. A precise device scrolls in pixels, a notched wheel in notches.
 *
 * What AppKit dispatches is queued as it happens and applied when the backend polls, so channels
 * change and mail is sent from the loop, not from inside AppKit.
 *
 * AppKit's own tracking loops (a slider dragged, a menu open) dequeue the events they track, so
 * some releases never pass the monitor: each poll lets go of what the system no longer holds. The
 * application stopping being active lets go of everything.
 */
final class AppkitInputBackend extends InputBackend
{
    /** Device-dependent modifier bits (IOLLEvent.h NX_DEVICE*KEYMASK; fn: NSEventModifierFlagFunction) by key code: the side a flags change changed. */
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

    private ?NSObject $monitor = null;

    private ?NSObject $observer = null;

    private bool $resigned = false;

    /** Something the monitor could not handle: thrown from the next poll, out of AppKit. */
    private ?Throwable $failure = null;

    /** @var list<Seen> */
    private array $queue = [];

    /** @var array<string, AppkitInputChannel> the channel each held button is captured by, by MouseButton->value */
    private array $captured = [];

    /** The channel the pointer was last over. */
    private ?AppkitInputChannel $hover = null;

    /** @var array<int, array{bool, bool}> each channel's focus and key-window state at the last poll, by channel id */
    private array $focus = [];

    /** @var array<int, array<int, Key>> keys each channel holds, by channel id then key code */
    private array $held = [];

    /** @var array<string, WeakMap<AppkitInputChannel, true>> channels with a scroll, pinch or rotation under way, by SeenKind name */
    private array $gestures = [];

    /** @var array<int, NSWindow> windows moved events were turned on for, by window number */
    private array $tracking = [];

    /** @var Closure(): int */
    private readonly Closure $buttonsDown;

    /** @var Closure(int): bool */
    private readonly Closure $keyDown;

    /**
     * @param (Closure(): int)|null $buttons the mouse buttons down now, as a bit mask; NSEvent::pressedMouseButtons() by default
     * @param (Closure(int): bool)|null $keys whether the key with a macOS key code is down now; CGEventSourceKeyState over the session's state by default
     *
     * @throws HumanInputException When AppKit is not here.
     */
    public function __construct(?Closure $buttons = null, ?Closure $keys = null)
    {
        if (! extension_loaded('appkit')) {
            throw new HumanInputException('ext-appkit is not loaded.');
        }
        if (PHP_OS_FAMILY !== 'Darwin') {
            throw new HumanInputException('AppKit runs on macOS only.');
        }
        $this->buttonsDown = $buttons ?? static fn (): int => NSEvent::pressedMouseButtons();
        $this->keyDown = $keys ?? static fn (int $code): bool => CGEventSource::keyState(CGEventSourceStateID::COMBINED_SESSION_STATE, $code);
    }

    public function name(): string
    {
        return 'appkit';
    }

    /** AppKit's own windows: whatever engine draws their canvases, the events are AppKit's. */
    public function serves(InputContext $context): bool
    {
        return $context->toolkit === 'appkit' && $context->os === 'mac';
    }

    public function connect(): static
    {
        if ($this->connected) {
            return $this;
        }

        $mask = NSEventMask::LEFT_MOUSE_DOWN->value | NSEventMask::LEFT_MOUSE_UP->value
            | NSEventMask::RIGHT_MOUSE_DOWN->value | NSEventMask::RIGHT_MOUSE_UP->value
            | NSEventMask::OTHER_MOUSE_DOWN->value | NSEventMask::OTHER_MOUSE_UP->value
            | NSEventMask::LEFT_MOUSE_DRAGGED->value | NSEventMask::RIGHT_MOUSE_DRAGGED->value | NSEventMask::OTHER_MOUSE_DRAGGED->value
            | NSEventMask::MOUSE_MOVED->value | NSEventMask::SCROLL_WHEEL->value
            | NSEventMask::MAGNIFY->value | NSEventMask::ROTATE->value | NSEventMask::PRESSURE->value
            | NSEventMask::KEY_UP->value;
        $self = \WeakReference::create($this);
        $this->monitor = NSEvent::addLocalMonitorForEventsMatchingMaskHandler($mask, static function (NSEvent $event) use ($self): NSEvent {
            $self->get()?->dispatched($event);

            return $event;
        });
        $this->observer = NSNotificationCenter::defaultCenter()->addObserverForNameObjectQueueUsingBlock(
            NSApplicationDidResignActiveNotification, null, null, static function () use ($self): void {
                if (! is_null($backend = $self->get())) {
                    $backend->resigned = true;
                }
            },
        );
        $this->connected = true;

        return $this;
    }

    public function disconnect(): void
    {
        if (! $this->connected) {
            return;
        }

        if (! is_null($this->monitor)) {
            NSEvent::removeMonitor($this->monitor);
        }
        if (! is_null($this->observer)) {
            NSNotificationCenter::defaultCenter()->removeObserver($this->observer);
        }
        $this->closeChannels();
        [$this->monitor, $this->observer, $this->queue, $this->captured, $this->hover, $this->focus, $this->held, $this->tracking, $this->resigned, $this->failure]
            = [null, null, [], [], null, [], [], [], false, null];
        $this->connected = false;
    }

    public function connected(): bool
    {
        return $this->connected;
    }

    /** @throws HumanInputException When $stage is not an AppKit canvas. */
    protected function makeChannel(ActorStage $stage): InputChannel
    {
        if (! $stage instanceof AppkitCanvas) {
            throw new HumanInputException('The appkit input backend reaches AppKit canvases only, not a '.$stage::class.'.');
        }
        $this->mail ??= static fn (object $mail) => null;
        $this->pads ??= static fn (): array => [];
        $self = \WeakReference::create($this);

        return new AppkitInputChannel($stage, $this->mail, $this->pads, static function (Seen $seen) use ($self): void {
            $self->get()?->enqueue($seen);
        });
    }

    /** @internal what a canvas's view and the monitor queue */
    public function enqueue(Seen $seen): void
    {
        $this->queue[] = $seen;
    }

    /**
     * Applies what AppKit dispatched since the last poll, then lets go of what the system no
     * longer holds and of the keys of a canvas that lost focus.
     *
     * @throws Throwable What the monitor failed with.
     */
    public function poll(): void
    {
        if (! is_null($failure = $this->failure)) {
            $this->failure = null;
            throw $failure;
        }
        if (! $this->connected) {
            return;
        }

        $channels = [];
        foreach ($this->channels() as $channel) {
            if ($channel instanceof AppkitInputChannel) {
                $channels[spl_object_id($channel)] = $channel;
            }
        }
        $this->track($channels);

        [$queue, $this->queue] = [$this->queue, []];
        foreach ($queue as $seen) {
            if (! $seen->to->closed()) {
                $this->apply($seen);
            }
        }

        $this->followFocus($channels);
        $this->reconcile($channels);

        if ($this->resigned) {
            $this->resigned = false;
            foreach ($channels as $id => $channel) {
                $channel->releaseAll();
                $this->held[$id] = [];
            }
            [$this->captured, $this->hover] = [[], null];
        }

        $this->held = array_intersect_key($this->held, $channels);
        $this->focus = array_intersect_key($this->focus, $channels);
    }

    /** What a key down types: no control characters, and none of the function keys' private-use characters (U+F700–U+F8FF). */
    public static function text(string $characters): string
    {
        return (string) preg_replace('/[\x{0}-\x{1F}\x{7F}\x{F700}-\x{F8FF}]/u', '', $characters);
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

    /** A scroll event's place in its gesture: the fingers' phase, or the coast after them. */
    public static function scrollPhase(int $phase, int $momentum): ScrollPhase
    {
        if ($momentum !== NSEventPhase::NONE->value) {
            return ($momentum & (NSEventPhase::ENDED->value | NSEventPhase::CANCELLED->value)) !== 0 ? ScrollPhase::MOMENTUM_ENDED : ScrollPhase::MOMENTUM;
        }

        return match (GesturePhase::of($phase)) {
            GesturePhase::BEGAN => ScrollPhase::BEGAN,
            GesturePhase::CHANGED => ScrollPhase::CHANGED,
            GesturePhase::ENDED => ScrollPhase::ENDED,
            GesturePhase::NONE => ScrollPhase::NONE,
        };
    }

    /**
     * The monitor: an event AppKit is dispatching. Routed now, while the layout is the one the user
     * saw; applied at the next poll.
     *
     * @internal
     */
    public function dispatched(NSEvent $event): void
    {
        $window = $event->window();
        if (is_null($window)) {
            return;                                              // over no window of this application
        }

        $this->dispatchedAt($event, $window, $event->locationInWindow());
    }

    /**
     * Routes an event AppKit dispatched in $window at $at, a point in the window's own coordinates.
     * dispatched() reads both from the event; an event made from a Quartz event cannot carry a
     * window-local point a test chooses (AppKit reads it from a private field), so tests hand them in.
     *
     * @internal
     */
    public function dispatchedAt(NSEvent $event, NSWindow $window, NSPoint $at): void
    {
        try {
            $this->routeEvent($event, $window, $at);
        } catch (Throwable $e) {
            $this->failure ??= $e;
        }
    }

    private function routeEvent(NSEvent $event, NSWindow $window, NSPoint $at): void
    {
        $type = $event->type();
        if ($type === NSEventType::KEY_UP) {
            $this->commandUp($event, $window);

            return;
        }

        $under = $this->under($window, $at);
        $flags = $event->modifierFlags();

        switch ($type) {
            case NSEventType::LEFT_MOUSE_DOWN:
            case NSEventType::RIGHT_MOUSE_DOWN:
            case NSEventType::OTHER_MOUSE_DOWN:
                $button = self::button($event);
                $this->hovers($under, $window, $at);
                if (is_null($button) || is_null($under)) {
                    return;
                }
                $this->captured[$button->value] = $under;
                $this->queue[] = $this->pointerSeen(SeenKind::Button, $under, $window, $at, flags: $flags, button: $button, down: true);

                return;

            case NSEventType::LEFT_MOUSE_UP:
            case NSEventType::RIGHT_MOUSE_UP:
            case NSEventType::OTHER_MOUSE_UP:
                $button = self::button($event);
                if (is_null($button)) {
                    return;
                }
                $to = $this->captured[$button->value] ?? null;
                unset($this->captured[$button->value]);
                if (! is_null($to)) {
                    $this->queue[] = $this->pointerSeen(SeenKind::Button, $to, $window, $at, flags: $flags, button: $button, down: false);
                }
                $this->hovers($under, $window, $at);

                return;

            case NSEventType::LEFT_MOUSE_DRAGGED:
            case NSEventType::RIGHT_MOUSE_DRAGGED:
            case NSEventType::OTHER_MOUSE_DRAGGED:
                $button = self::button($event);
                $to = is_null($button) ? null : ($this->captured[$button->value] ?? null);
                if (! is_null($to)) {
                    $this->queue[] = $this->pointerSeen(SeenKind::Pointer, $to, $window, $at);
                }
                $this->hovers($under, $window, $at, except: $to);

                return;

            case NSEventType::MOUSE_MOVED:
                $this->hovers($under, $window, $at);

                return;

            case NSEventType::SCROLL_WHEEL:
                $this->hovers($under, $window, $at);
                if (! is_null($under)) {
                    $precise = $event->hasPreciseScrollingDeltas();
                    $scale = $precise ? $window->backingScaleFactor() : 1.0;
                    $this->queue[] = $this->pointerSeen(SeenKind::Scroll, $under, $window, $at,
                        dx: -$event->scrollingDeltaX() * $scale, dy: -$event->scrollingDeltaY() * $scale, precise: $precise,
                        inverted: $event->isDirectionInvertedFromDevice(), phase: self::bits($event->phase()), momentum: self::bits($event->momentumPhase()));
                }

                return;

            case NSEventType::MAGNIFY:
                if (! is_null($under)) {
                    $this->queue[] = $this->pointerSeen(SeenKind::Magnify, $under, $window, $at, amount: $event->magnification(), phase: self::bits($event->phase()));
                }

                return;

            case NSEventType::ROTATE:
                if (! is_null($under)) {
                    $this->queue[] = $this->pointerSeen(SeenKind::Rotate, $under, $window, $at, amount: $event->rotation(), phase: self::bits($event->phase()));
                }

                return;

            case NSEventType::PRESSURE:
                // force belongs to the click it presses: the canvas the left button is captured by
                $to = $this->captured[MouseButton::LEFT->value] ?? $under;
                if (! is_null($to)) {
                    $this->queue[] = $this->pointerSeen(SeenKind::Pressure, $to, $window, $at,
                        amount: $event->pressure(), stage: $event->stage(), transition: $event->stageTransition());
                }

                return;
        }
    }

    /** A key-up AppKit keeps from the first responder while Command is down. */
    private function commandUp(NSEvent $event, NSWindow $window): void
    {
        if (($event->modifierFlags() & NSEventModifierFlags::COMMAND->value) === 0) {
            return;
        }
        $responder = $window->firstResponder();
        foreach ($this->channels() as $channel) {
            if ($channel instanceof AppkitInputChannel && $channel->canvas()->native() === $responder) {
                $this->queue[] = new Seen(SeenKind::Key, $channel, flags: $event->modifierFlags(), code: $event->keyCode(), down: false);

                return;
            }
        }
    }

    /**
     * The pointer is over $under now (null: no canvas with open input): the canvas it was over and
     * left is told it is outside, and the one it is over gets where it is. $except already got a
     * position from this event.
     */
    private function hovers(?AppkitInputChannel $under, NSWindow $window, NSPoint $at, ?AppkitInputChannel $except = null): void
    {
        if (! is_null($this->hover) && $this->hover !== $under && $this->hover !== $except && ! $this->hover->closed()) {
            $this->queue[] = $this->pointerSeen(SeenKind::Pointer, $this->hover, $window, $at);
        }
        $this->hover = $under;
        if (! is_null($under) && $under !== $except) {
            $this->queue[] = $this->pointerSeen(SeenKind::Pointer, $under, $window, $at);
        }
    }

    /** The open channel whose canvas is under a point in $window, innermost first; null for none. */
    private function under(NSWindow $window, NSPoint $at): ?AppkitInputChannel
    {
        $views = [];
        foreach ($this->channels() as $channel) {
            if ($channel instanceof AppkitInputChannel && $channel->canvas()->native()->window() === $window) {
                $views[] = $channel;
            }
        }
        if ($views === []) {
            return null;
        }

        for ($view = $window->contentView()?->hitTest($at); ! is_null($view); $view = $view->superview()) {
            foreach ($views as $channel) {
                if ($channel->canvas()->native() === $view) {
                    return $channel;
                }
            }
        }

        return null;
    }

    /**
     * A pointer event for $to, its position worked out in $to's canvas's pixels from its top left:
     * the window point through the view's own conversion, flipped when the view is not, times the
     * window's backing scale.
     */
    /**
     * Whether a gesture beginning on $channel is already under way. AppKit begins one twice when the
     * fingers rest before they move (MAY_BEGIN, then BEGAN; measured on a trackpad): the second
     * start continues it. Anything but a begin or a change ends it.
     */
    private function underway(SeenKind $kind, AppkitInputChannel $channel, bool $begins, bool $ends): bool
    {
        $open = $this->gestures[$kind->name] ??= new WeakMap();
        $again = $begins && isset($open[$channel]);
        if ($begins) {
            $open[$channel] = true;
        } elseif ($ends) {
            unset($open[$channel]);
        }

        return $again;
    }

    private function pointerSeen(SeenKind $kind, AppkitInputChannel $to, NSWindow $window, NSPoint $at, mixed ...$fields): Seen
    {
        $view = $to->canvas()->native();
        $local = $view->convertPointFromView($at, null);
        $bounds = $view->bounds();
        $y = $view->isFlipped() ? $local->y : $bounds->height - $local->y;
        $inside = $local->x >= 0.0 && $local->x < $bounds->width && $y >= 0.0 && $y < $bounds->height;
        $scale = $window->backingScaleFactor();

        return new Seen($kind, $to, ...['x' => $local->x * $scale, 'y' => $y * $scale, 'inside' => $inside, ...$fields]);
    }

    private function apply(Seen $seen): void
    {
        $channel = $seen->to;
        $id = spl_object_id($channel);

        switch ($seen->kind) {
            case SeenKind::Key:
                $channel->modifiers(self::modifiers($seen->flags));
                if (! $seen->repeat) {
                    $this->hold($channel, $id, $seen->code, $seen->down);
                }

                return;

            case SeenKind::Flags:
                $channel->modifiers(self::modifiers($seen->flags));
                if ($seen->code === self::CAPS_LOCK) {
                    // Caps Lock reports its lock toggling, not a key held: each change is one press.
                    $channel->key(Key::CAPS_LOCK, true);
                    $channel->key(Key::CAPS_LOCK, false);
                } elseif (isset(self::SIDES[$seen->code])) {
                    $this->hold($channel, $id, $seen->code, ($seen->flags & self::SIDES[$seen->code]) !== 0);
                }

                return;

            case SeenKind::Text:
                $channel->text(self::text($seen->text));

                return;

            case SeenKind::Button:
                $channel->modifiers(self::modifiers($seen->flags));
                $channel->pointer($seen->x, $seen->y, $seen->inside);
                $channel->button($seen->button, $seen->down);

                return;

            case SeenKind::Pointer:
                $channel->pointer($seen->x, $seen->y, $seen->inside);

                return;

            case SeenKind::Scroll:
                $channel->pointer($seen->x, $seen->y, $seen->inside);
                $phase = self::scrollPhase($seen->phase, $seen->momentum);
                if ($this->underway($seen->kind, $channel, $phase === ScrollPhase::BEGAN, $phase !== ScrollPhase::CHANGED)) {
                    $phase = ScrollPhase::CHANGED;
                }
                $channel->scroll($seen->dx, $seen->dy, $seen->precise, $seen->inverted, $phase);

                return;

            case SeenKind::Magnify:
                $phase = GesturePhase::of($seen->phase);
                if ($this->underway($seen->kind, $channel, $phase === GesturePhase::BEGAN, $phase !== GesturePhase::CHANGED)) {
                    $phase = GesturePhase::CHANGED;
                }
                $channel->pinch($seen->amount, $phase);

                return;

            case SeenKind::Rotate:
                $phase = GesturePhase::of($seen->phase);
                if ($this->underway($seen->kind, $channel, $phase === GesturePhase::BEGAN, $phase !== GesturePhase::CHANGED)) {
                    $phase = GesturePhase::CHANGED;
                }
                $channel->rotate($seen->amount, $phase);

                return;

            case SeenKind::Pressure:
                $channel->force($seen->amount, $seen->stage, $seen->transition);

                return;
        }
    }

    private function hold(AppkitInputChannel $channel, int $id, int $code, bool $down): void
    {
        $key = MacKeycodes::key($code);
        $channel->key($key, $down);
        if ($down) {
            $this->held[$id][$code] = $key;
        } else {
            unset($this->held[$id][$code]);
        }
    }

    /**
     * A canvas that stopped being first responder, or whose window stopped being key, lets go of
     * its keys; a captured button stays down until it is released.
     *
     * @param array<int, AppkitInputChannel> $channels
     */
    private function followFocus(array $channels): void
    {
        foreach ($channels as $id => $channel) {
            $view = $channel->canvas()->native();
            $window = $view->window();
            $now = [! is_null($window) && $window->firstResponder() === $view, ! is_null($window) && $window->isKeyWindow()];
            [$wasFocused, $wasKey] = $this->focus[$id] ?? [false, false];
            if ($wasFocused && (! $now[0] || ($wasKey && ! $now[1]))) {
                $channel->releaseKeys();
                $this->held[$id] = [];
            }
            $this->focus[$id] = $now;
        }
    }

    /**
     * Lets go of what is held here and no longer held by the system: the releases a tracking loop
     * dequeued itself.
     *
     * @param array<int, AppkitInputChannel> $channels
     */
    private function reconcile(array $channels): void
    {
        $buttons = ($this->buttonsDown)();
        foreach ($this->captured as $value => $channel) {
            if (($buttons & (1 << self::BUTTON_BITS[$value])) === 0) {
                unset($this->captured[$value]);
            }
        }
        foreach ($channels as $channel) {
            foreach (MouseButton::cases() as $button) {
                if ($channel->mouse()->isDown($button) && ($buttons & (1 << self::BUTTON_BITS[$button->value])) === 0) {
                    $channel->button($button, false);
                }
            }
        }

        foreach ($this->held as $id => $keys) {
            $channel = $channels[$id] ?? null;
            if (is_null($channel)) {
                continue;
            }
            $released = false;
            foreach ($keys as $code => $key) {
                if (! ($this->keyDown)($code)) {
                    $channel->key($key, false);
                    unset($this->held[$id][$code]);
                    $released = true;
                }
            }
            if ($released) {
                $now = $channel->keyboard()->modifiers();
                $holds = fn (string $modifier): bool => array_any(self::MODIFIER_KEYS[$modifier], fn (Key $key): bool => $channel->keyboard()->isDown($key));
                $channel->modifiers(new Modifiers(
                    shift: $now->shift && $holds('shift'),
                    ctrl: $now->ctrl && $holds('ctrl'),
                    alt: $now->alt && $holds('alt'),
                    meta: $now->meta && $holds('meta'),
                ));
            }
        }
    }

    /**
     * Moved events on in the windows of the open channels: AppKit sends a window none unless asked.
     *
     * @param array<int, AppkitInputChannel> $channels
     */
    private function track(array $channels): void
    {
        foreach ($channels as $channel) {
            $window = $channel->canvas()->native()->window();
            if (! is_null($window) && ! isset($this->tracking[$window->windowNumber()])) {
                $window->setAcceptsMouseMovedEvents(true);
                $this->tracking[$window->windowNumber()] = $window;
            }
        }
    }

    /** Left and right by type (a built event's button number reads 0), others by number: 2 middle, 3 and 4 the side buttons. */
    private static function button(NSEvent $event): ?MouseButton
    {
        return match ($event->type()) {
            NSEventType::LEFT_MOUSE_DOWN, NSEventType::LEFT_MOUSE_UP, NSEventType::LEFT_MOUSE_DRAGGED => MouseButton::LEFT,
            NSEventType::RIGHT_MOUSE_DOWN, NSEventType::RIGHT_MOUSE_UP, NSEventType::RIGHT_MOUSE_DRAGGED => MouseButton::RIGHT,
            default => match ($event->buttonNumber()) {
                2 => MouseButton::MIDDLE,
                3 => MouseButton::X1,
                4 => MouseButton::X2,
                default => null,
            },
        };
    }

    private static function bits(NSEventPhase|int $phase): int
    {
        return $phase instanceof NSEventPhase ? $phase->value : $phase;
    }
}
