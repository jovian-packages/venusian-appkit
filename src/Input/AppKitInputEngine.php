<?php

namespace Jovian\Venusian\AppKit\Input;

use Jovian\Bindings\AppKit\Enums\NSEventMask;
use Jovian\Bindings\AppKit\Enums\NSEventModifierFlags;
use Jovian\Bindings\AppKit\Enums\NSEventType;
use Surface\Contracts\HumanInput\GamepadAxis;
use Surface\Contracts\HumanInput\GamepadButton;
use Surface\Contracts\HumanInput\InputEngine;
use Surface\Contracts\HumanInput\InputEngineDriver;
use Surface\Contracts\HumanInput\Key;
use Surface\Contracts\HumanInput\Modifiers;
use Surface\Contracts\HumanInput\MouseButton;
use Surface\HumanInput\Devices\GameController;
use Surface\HumanInput\Devices\GamePad;
use Surface\HumanInput\Devices\Keyboard;
use Surface\HumanInput\Devices\Mouse;

/**
 * The appkit input engine (`input.appkit`). Keys and the mouse come from the
 * ext's NSEvent tap, which records while `os` pumps NSApp; this engine ticks
 * after `os` and never pumps itself. Gamepads come from GameController:
 * connect / disconnect notifications only mark the set dirty, and every
 * attached pad is read each poll. When the app resigns active, every key and
 * mouse button is released on the next poll.
 */
final class AppKitInputEngine implements InputEngineDriver
{
    private bool $connected = false;

    /** Set by a GameController connect / disconnect notification; the next poll rescans. */
    private bool $dirty = false;

    /** Set when the app resigns active; the next poll releases every key and mouse button. */
    private bool $release_all = false;

    private ?Keyboard $keyboard = null;

    private ?Mouse $mouse = null;

    /** @var array<int, GamePad> controller handle → device (GameController when extended) */
    private array $pads = [];

    /** @var array<int, array{name: string, content_width: float, content_height: float}>|null this apply()'s window lookup, read on first need */
    private ?array $window_lookup = null;

    /** @var list<int>|null the window numbers last handed to the tap's key sink */
    private ?array $key_sink = null;

    public function __construct(
        private readonly InputTap $tap,
        private readonly GamepadSource $gamepads,
        private readonly WindowSpace $windows,
        private readonly AppActivity $activity,
    ) {}

    public function engine(): InputEngine
    {
        return InputEngine::APPKIT;
    }

    public function connect(): static
    {
        if ($this->connected) {
            return $this;
        }

        $this->keyboard = new Keyboard();
        $this->mouse = new Mouse();
        $this->tap->watch(self::mask());
        $this->gamepads->onChange(function (): void {
            $this->dirty = true;
        });
        $this->activity->onResign(function (): void {
            $this->release_all = true;
        });
        $this->connected = true;
        $this->syncGamepads();

        return $this;
    }

    public function disconnect(): void
    {
        if (! $this->connected) {
            return;
        }

        $this->tap->watch(0);
        $this->key_sink = null;
        $this->gamepads->stop();
        $this->activity->stop();
        $this->pads = [];
        $this->keyboard = null;
        $this->mouse = null;
        $this->dirty = false;
        $this->release_all = false;
        $this->connected = false;
    }

    public function connected(): bool
    {
        return $this->connected;
    }

    public function poll(): void
    {
        if (! $this->connected) {
            return;
        }

        $this->keyboard->settle();
        $this->mouse->settle();
        foreach ($this->pads as $pad) {
            $pad->settle();
        }

        if ($this->release_all) {
            $this->releaseAll();
        }

        $this->windows->acceptMouseMoves();
        $this->syncKeySink();
        $this->apply($this->tap->drain());

        if ($this->dirty) {
            $this->syncGamepads();
        }

        $this->readGamepads();
    }

    /**
     * @internal the pure half of poll(): fold drained tap records into the devices
     *
     * @param list<array<string, mixed>> $events drainInput() records, oldest first
     */
    public function apply(array $events): void
    {
        if (! $this->connected) {
            return;
        }

        $this->window_lookup = null;

        foreach ($events as $event) {
            $type = NSEventType::tryFrom((int) ($event['type'] ?? -1));

            match ($type) {
                NSEventType::KEY_DOWN => $this->applyKeyDown($event),
                NSEventType::KEY_UP => $this->applyKeyUp($event),
                NSEventType::FLAGS_CHANGED => $this->applyFlagsChanged($event),
                NSEventType::LEFT_MOUSE_DOWN, NSEventType::RIGHT_MOUSE_DOWN, NSEventType::OTHER_MOUSE_DOWN => $this->applyMouse($event, true),
                NSEventType::LEFT_MOUSE_UP, NSEventType::RIGHT_MOUSE_UP, NSEventType::OTHER_MOUSE_UP => $this->applyMouse($event, false),
                NSEventType::MOUSE_MOVED, NSEventType::LEFT_MOUSE_DRAGGED, NSEventType::RIGHT_MOUSE_DRAGGED, NSEventType::OTHER_MOUSE_DRAGGED => $this->applyMouse($event, null),
                NSEventType::SCROLL_WHEEL => $this->applyScroll($event),
                default => null,
            };
        }

        $this->window_lookup = null;
    }

    public function keyboard(): ?Keyboard
    {
        return $this->keyboard;
    }

    public function mouse(): ?Mouse
    {
        return $this->mouse;
    }

    /** @return array<string, GamePad> micro-profile pads only */
    public function gamePads(): array
    {
        $keyed = [];
        foreach ($this->pads as $pad) {
            if (! $pad instanceof GameController) {
                $keyed[$pad->id()] = $pad;
            }
        }

        return $keyed;
    }

    /** @return array<string, GameController> extended-profile pads */
    public function gameControllers(): array
    {
        $keyed = [];
        foreach ($this->pads as $pad) {
            if ($pad instanceof GameController) {
                $keyed[$pad->id()] = $pad;
            }
        }

        return $keyed;
    }

    /** Key down / up, flags changed, every mouse button, drag and move, and the wheel. */
    private static function mask(): int
    {
        return NSEventMask::KEY_DOWN->value
            | NSEventMask::KEY_UP->value
            | NSEventMask::FLAGS_CHANGED->value
            | NSEventMask::LEFT_MOUSE_DOWN->value
            | NSEventMask::LEFT_MOUSE_UP->value
            | NSEventMask::LEFT_MOUSE_DRAGGED->value
            | NSEventMask::RIGHT_MOUSE_DOWN->value
            | NSEventMask::RIGHT_MOUSE_UP->value
            | NSEventMask::RIGHT_MOUSE_DRAGGED->value
            | NSEventMask::OTHER_MOUSE_DOWN->value
            | NSEventMask::OTHER_MOUSE_UP->value
            | NSEventMask::OTHER_MOUSE_DRAGGED->value
            | NSEventMask::MOUSE_MOVED->value
            | NSEventMask::SCROLL_WHEEL->value;
    }

    /** @param array<string, mixed> $event */
    private function applyKeyDown(array $event): void
    {
        $flags = (int) ($event['modifierFlags'] ?? 0);
        $this->releaseLiftedModifiers($flags);

        // An auto-repeat still types; only the press is already counted.
        if (! (bool) ($event['isARepeat'] ?? false)) {
            $this->keyboard->update(KeyCodeMap::key((int) ($event['keyCode'] ?? -1)), true);
        }

        $withheld = NSEventModifierFlags::NS_EVENT_MODIFIER_FLAG_COMMAND->value | NSEventModifierFlags::NS_EVENT_MODIFIER_FLAG_CONTROL->value;
        if (($flags & $withheld) === 0) {
            $this->keyboard->appendText(self::printable((string) ($event['characters'] ?? '')));
        }

        $this->keyboard->setModifiers(self::modifiers($flags));
    }

    /** @param array<string, mixed> $event */
    private function applyKeyUp(array $event): void
    {
        $this->releaseLiftedModifiers((int) ($event['modifierFlags'] ?? 0));
        $this->keyboard
            ->update(KeyCodeMap::key((int) ($event['keyCode'] ?? -1)), false)
            ->setModifiers(self::modifiers((int) ($event['modifierFlags'] ?? 0)));
    }

    /**
     * A modifier went down or up. Which one comes from keyCode; its state from
     * that key's device-dependent bit, so left and right are told apart.
     *
     * @param array<string, mixed> $event
     */
    private function applyFlagsChanged(array $event): void
    {
        $flags = (int) ($event['modifierFlags'] ?? 0);
        $key = KeyCodeMap::key((int) ($event['keyCode'] ?? -1));
        $bit = self::modifierBit($key);

        if (! is_null($bit)) {
            $this->keyboard->update($key, ($flags & $bit) !== 0);
        }

        $this->releaseLiftedModifiers($flags);
        $this->keyboard->setModifiers(self::modifiers($flags));
    }

    /**
     * A sided modifier whose up event was missed (released while another app
     * was key) is released as soon as an event shows its device bit clear.
     * Releases only; caps lock and fn are left alone.
     */
    private function releaseLiftedModifiers(int $flags): void
    {
        foreach (self::sidedModifiers() as $key) {
            if ($this->keyboard->isDown($key) && ($flags & self::modifierBit($key)) === 0) {
                $this->keyboard->update($key, false);
            }
        }
    }

    /** @return list<Key> the modifiers with a left and a right key */
    private static function sidedModifiers(): array
    {
        return [
            Key::LEFT_SHIFT, Key::RIGHT_SHIFT,
            Key::LEFT_CTRL, Key::RIGHT_CTRL,
            Key::LEFT_ALT, Key::RIGHT_ALT,
            Key::LEFT_META, Key::RIGHT_META,
        ];
    }

    /**
     * Focus went elsewhere: every held key and mouse button is released, with
     * release edges, and the modifiers cleared.
     */
    private function releaseAll(): void
    {
        $this->release_all = false;

        foreach ($this->keyboard->downKeys() as $key) {
            $this->keyboard->update($key, false);
        }
        $this->keyboard->setModifiers(new Modifiers());

        foreach (MouseButton::cases() as $button) {
            $this->mouse->update($button, false);
        }
    }

    /**
     * @param array<string, mixed> $event
     * @param bool|null $down true on *_MOUSE_DOWN, false on *_MOUSE_UP, null on moves and drags
     */
    private function applyMouse(array $event, ?bool $down): void
    {
        $window = $this->windowLookup()[(int) ($event['windowNumber'] ?? 0)] ?? null;

        if (! is_null($window)) {
            $location = (array) ($event['locationInWindow'] ?? []);
            $x = (float) ($location['x'] ?? 0.0);
            $y = $window['content_height'] - (float) ($location['y'] ?? 0.0);
            $inside = $x >= 0.0 && $x < $window['content_width'] && $y >= 0.0 && $y < $window['content_height'];

            // A drag or move reported past the content edge is under no window.
            $this->mouse->setPosition($x, $y, $inside ? $window['name'] : null);
        }

        // AppKit's deltaY is already y-down.
        $this->mouse->addMotion((float) ($event['deltaX'] ?? 0.0), (float) ($event['deltaY'] ?? 0.0));

        if (is_null($down)) {
            return;
        }

        $button = match ((int) ($event['buttonNumber'] ?? -1)) {
            0 => MouseButton::LEFT,
            1 => MouseButton::RIGHT,
            2 => MouseButton::MIDDLE,
            3 => MouseButton::X1,
            4 => MouseButton::X2,
            default => null,
        };

        if (! is_null($button)) {
            $this->mouse->update($button, $down);
        }
    }

    /**
     * Trackpads and Magic Mice report precise deltas in points; a line is
     * taken as ten points. Surface's wheel is physical (dy > 0 = rolled
     * away), so natural scrolling's inverted deltas are flipped back.
     *
     * @param array<string, mixed> $event
     */
    private function applyScroll(array $event): void
    {
        $scale = (bool) ($event['hasPreciseScrollingDeltas'] ?? false) ? 10.0 : 1.0;
        $sign = (bool) ($event['isDirectionInvertedFromDevice'] ?? false) ? -1.0 : 1.0;

        $this->mouse->addWheel(
            $sign * (float) ($event['scrollingDeltaX'] ?? 0.0) / $scale,
            $sign * (float) ($event['scrollingDeltaY'] ?? 0.0) / $scale,
        );
    }

    /** @return array<int, array{name: string, content_width: float, content_height: float}> */
    private function windowLookup(): array
    {
        return $this->window_lookup ??= $this->windows->windows();
    }

    private function syncGamepads(): void
    {
        $this->dirty = false;
        $handles = $this->gamepads->handles();

        foreach ($handles as $handle) {
            if (array_key_exists($handle, $this->pads)) {
                continue;
            }

            $id = "gc-{$handle}";
            $name = $this->gamepads->name($handle);
            $buttons = array_map(
                static fn (string $button): GamepadButton => GamepadButton::from($button),
                array_keys($this->gamepads->buttons($handle)),
            );

            $this->pads[$handle] = $this->gamepads->extended($handle)
                ? new GameController($id, $name, $buttons, GamepadAxis::cases())
                : new GamePad($id, $name, $buttons);
        }

        $this->pads = array_intersect_key($this->pads, array_flip($handles));
    }

    private function readGamepads(): void
    {
        foreach ($this->pads as $handle => $pad) {
            foreach ($this->gamepads->buttons($handle) as $button => $down) {
                $pad->update(GamepadButton::from($button), $down);
            }

            if (! $pad instanceof GameController) {
                continue;
            }

            foreach ($this->gamepads->axes($handle) as $axis => $value) {
                $pad->setAxis(GamepadAxis::from($axis), $value);
            }
        }
    }

    private static function modifiers(int $flags): Modifiers
    {
        return new Modifiers(
            shift: ($flags & NSEventModifierFlags::NS_EVENT_MODIFIER_FLAG_SHIFT->value) !== 0,
            ctrl: ($flags & NSEventModifierFlags::NS_EVENT_MODIFIER_FLAG_CONTROL->value) !== 0,
            alt: ($flags & NSEventModifierFlags::NS_EVENT_MODIFIER_FLAG_OPTION->value) !== 0,
            meta: ($flags & NSEventModifierFlags::NS_EVENT_MODIFIER_FLAG_COMMAND->value) !== 0,
        );
    }

    /**
     * The modifierFlags bit that says a modifier key is down: the IOKit
     * device-dependent bits (NX_DEVICE*KEYMASK) for sided keys, the
     * device-independent flag for caps lock and fn. Null for other keys.
     */
    private static function modifierBit(Key $key): ?int
    {
        return match ($key) {
            Key::LEFT_CTRL => 0x0001,
            Key::LEFT_SHIFT => 0x0002,
            Key::RIGHT_SHIFT => 0x0004,
            Key::LEFT_META => 0x0008,
            Key::RIGHT_META => 0x0010,
            Key::LEFT_ALT => 0x0020,
            Key::RIGHT_ALT => 0x0040,
            Key::RIGHT_CTRL => 0x2000,
            Key::CAPS_LOCK => NSEventModifierFlags::NS_EVENT_MODIFIER_FLAG_CAPS_LOCK->value,
            Key::FUNCTION => NSEventModifierFlags::NS_EVENT_MODIFIER_FLAG_FUNCTION->value,
            default => null,
        };
    }

    /** Drop C0 controls, DEL and the private-use function-key range (U+F700–U+F8FF). */
    private static function printable(string $characters): string
    {
        return (string) preg_replace('/[\x{0000}-\x{001F}\x{007F}\x{F700}-\x{F8FF}]/u', '', $characters);
    }

    /** Keys no view in our windows takes are consumed, so AppKit does not beep; handed over only when the set changes. */
    private function syncKeySink(): void
    {
        $numbers = array_keys($this->windows->windows());
        sort($numbers);

        if ($numbers !== $this->key_sink) {
            $this->tap->swallowKeysIn($numbers);
            $this->key_sink = $numbers;
        }
    }
}
