<?php

namespace Jovian\Toolkits\Appkit\Input\Mail;

use Jovian\Toolkits\Appkit\Input\GesturePhase;
use Surface\Contracts\Graphics\ActorStage;

/** The stage was pinched: the step's change in scale, positive spreading the fingers. */
final readonly class Pinched
{
    public function __construct(
        public ActorStage $stage,
        public float $magnification,
        public GesturePhase $phase,
    ) {}
}
