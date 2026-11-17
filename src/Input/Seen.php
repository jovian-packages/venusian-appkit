<?php

namespace Jovian\Toolkits\Appkit\Input;

use Surface\Contracts\HumanInput\MouseButton;

/**
 * One AppKit event, copied as AppKit dispatches it and already routed to the channel it belongs to,
 * applied when the backend polls. Pointer positions are the channel's canvas's pixels, from its top
 * left, worked out at dispatch so a later layout change does not move them. Fields a kind does not
 * use stay at their defaults.
 */
final readonly class Seen
{
    public function __construct(
        public SeenKind $kind,
        public AppkitInputChannel $to,
        public int $flags = 0,
        public int $code = 0,
        public bool $down = false,
        public bool $repeat = false,
        public string $text = '',
        public ?MouseButton $button = null,
        public float $x = 0.0,
        public float $y = 0.0,
        public bool $inside = false,
        public float $dx = 0.0,
        public float $dy = 0.0,
        public bool $precise = false,
        public bool $inverted = false,
        public int $phase = 0,
        public int $momentum = 0,
        public float $amount = 0.0,
        public int $stage = 0,
        public float $transition = 0.0,
    ) {}
}
