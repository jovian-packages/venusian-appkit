<?php

namespace Jovian\Toolkits\Appkit\Input;

use NSEvent;
use NSEventType;
use Surface\Contracts\HumanInput\MouseButton;

/**
 * One AppKit event, read as the session pumps it. $window is the event's window number (0 for an
 * event made from a Quartz event). $x/$y is the screen point (origin bottom left of the main
 * screen) of a pointer event. Fields a kind does not use stay at zero.
 */
final readonly class SeenEvent
{
    public function __construct(
        public SeenKind $kind,
        public int $window,
        public int $code = 0,
        public bool $down = false,
        public bool $repeat = false,
        public int $flags = 0,
        public string $text = '',
        public ?MouseButton $button = null,
        public float $x = 0.0,
        public float $y = 0.0,
        public float $dx = 0.0,
        public float $dy = 0.0,
        public bool $precise = false,
        public bool $inverted = false,
    ) {}

    /** The copy of $event, or null for an event the engine does not read. */
    public static function from(NSEvent $event): ?self
    {
        $type = $event->type();
        $window = $event->windowNumber();

        return match ($type) {
            NSEventType::KEY_DOWN, NSEventType::KEY_UP => new self(SeenKind::Key, $window, code: $event->keyCode(),
                down: $type === NSEventType::KEY_DOWN, repeat: $event->isARepeat(), flags: $event->modifierFlags(),
                text: $type === NSEventType::KEY_DOWN ? ($event->characters() ?? '') : ''),
            NSEventType::FLAGS_CHANGED => new self(SeenKind::Flags, $window, code: $event->keyCode(), flags: $event->modifierFlags()),
            NSEventType::LEFT_MOUSE_DOWN, NSEventType::LEFT_MOUSE_UP, NSEventType::RIGHT_MOUSE_DOWN, NSEventType::RIGHT_MOUSE_UP,
            NSEventType::OTHER_MOUSE_DOWN, NSEventType::OTHER_MOUSE_UP => self::button($event, $type, $window),
            NSEventType::MOUSE_MOVED, NSEventType::LEFT_MOUSE_DRAGGED, NSEventType::RIGHT_MOUSE_DRAGGED, NSEventType::OTHER_MOUSE_DRAGGED => new self(
                SeenKind::Motion, $window, ...self::screen($event), dx: $event->deltaX(), dy: $event->deltaY()),
            NSEventType::SCROLL_WHEEL => new self(SeenKind::Wheel, $window, ...self::screen($event), dx: $event->deltaX(), dy: $event->deltaY(),
                precise: $event->hasPreciseScrollingDeltas(), inverted: $event->isDirectionInvertedFromDevice()),
            default => null,
        };
    }

    /** A click; left and right by type (a built event's button number reads 0), others by number: 2 middle, 3 and 4 the side buttons. */
    private static function button(NSEvent $event, NSEventType|int $type, int $window): ?self
    {
        $button = match ($type) {
            NSEventType::LEFT_MOUSE_DOWN, NSEventType::LEFT_MOUSE_UP => MouseButton::LEFT,
            NSEventType::RIGHT_MOUSE_DOWN, NSEventType::RIGHT_MOUSE_UP => MouseButton::RIGHT,
            default => match ($event->buttonNumber()) {
                2 => MouseButton::MIDDLE,
                3 => MouseButton::X1,
                4 => MouseButton::X2,
                default => null,
            },
        };
        if (is_null($button)) {
            return null;
        }
        $down = in_array($type, [NSEventType::LEFT_MOUSE_DOWN, NSEventType::RIGHT_MOUSE_DOWN, NSEventType::OTHER_MOUSE_DOWN], true);

        return new self(SeenKind::Button, $window, ...self::screen($event), down: $down, button: $button);
    }

    /** @return array{x: float, y: float} the screen point: in its window's frame, or the screen itself for an event with no window */
    private static function screen(NSEvent $event): array
    {
        $at = $event->locationInWindow();
        $frame = $event->window()?->frame();

        return is_null($frame) ? ['x' => $at->x, 'y' => $at->y] : ['x' => $frame->x + $at->x, 'y' => $frame->y + $at->y];
    }
}
