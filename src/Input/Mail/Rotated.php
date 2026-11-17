<?php

namespace Jovian\Toolkits\Appkit\Input\Mail;

use Jovian\Toolkits\Appkit\Input\GesturePhase;
use Surface\Contracts\Graphics\ActorStage;

/** The stage was rotated with two fingers: the step, in degrees, positive counterclockwise. */
final readonly class Rotated
{
    public function __construct(
        public ActorStage $stage,
        public float $degrees,
        public GesturePhase $phase,
    ) {}
}
