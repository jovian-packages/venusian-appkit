<?php

namespace Jovian\Toolkits\Appkit\Input;

use NSEventPhase;

/**
 * Where a pinch or a rotation sits in its gesture: BEGAN as the fingers start, CHANGED as they
 * move, ENDED as they lift (a cancelled gesture ends ENDED too). NONE outside a gesture.
 */
enum GesturePhase: string
{
    case NONE = 'none';
    case BEGAN = 'began';
    case CHANGED = 'changed';
    case ENDED = 'ended';

    /** An event's NSEventPhase: MAY_BEGIN (fingers down, not moving yet) begins; STATIONARY changes nothing yet still changes. */
    public static function of(NSEventPhase|int $phase): self
    {
        $bits = $phase instanceof NSEventPhase ? $phase->value : $phase;

        return match (true) {
            ($bits & (NSEventPhase::ENDED->value | NSEventPhase::CANCELLED->value)) !== 0 => self::ENDED,
            ($bits & (NSEventPhase::BEGAN->value | NSEventPhase::MAY_BEGIN->value)) !== 0 => self::BEGAN,
            ($bits & (NSEventPhase::CHANGED->value | NSEventPhase::STATIONARY->value)) !== 0 => self::CHANGED,
            default => self::NONE,
        };
    }
}
