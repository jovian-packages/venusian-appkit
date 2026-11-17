<?php

namespace Jovian\Toolkits\Appkit\Input\Mail;

use Surface\Contracts\Graphics\ActorStage;

/** A click on the stage pressed harder or softer: see AppleMouse for what each value is. */
final readonly class ForceChanged
{
    public function __construct(
        public ActorStage $stage,
        public float $pressure,
        public int $clickStage,
        public float $transition,
    ) {}
}
