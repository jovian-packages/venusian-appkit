<?php

namespace Jovian\Toolkits\Appkit\Input;

use Closure;
use Jovian\Toolkits\Appkit\Input\Mail\ForceChanged;
use Jovian\Toolkits\Appkit\Input\Mail\Pinched;
use Jovian\Toolkits\Appkit\Input\Mail\Rotated;
use Jovian\Toolkits\Appkit\Primitives\AppkitCanvas;
use Surface\HumanInput\Devices\GamePad;
use Surface\HumanInput\InputChannel;

/**
 * An AppKit canvas's input. Its pointer is an AppleMouse. What its canvas's view sees as first
 * responder (keys, text) and what the backend's monitor sees over it (the pointer) is queued
 * with see() as AppKit dispatches it, and applied as the backend polls.
 */
class AppkitInputChannel extends InputChannel
{
    /**
     * @param Closure(object): void $mail
     * @param Closure(): array<string, GamePad> $pads
     * @param Closure(Seen): void $queue the backend's queue
     */
    public function __construct(
        AppkitCanvas $stage,
        Closure $mail,
        Closure $pads,
        private readonly Closure $queue,
    ) {
        parent::__construct($stage, $mail, $pads);
    }

    protected function makeMouse(): AppleMouse
    {
        return new AppleMouse();
    }

    public function canvas(): AppkitCanvas
    {
        /** @var AppkitCanvas */
        return $this->stage;
    }

    /** Queues what AppKit just dispatched, for the next poll. */
    public function see(Seen $seen): void
    {
        if (! $this->closed) {
            ($this->queue)($seen);
        }
    }

    public function mouse(): AppleMouse
    {
        $this->open();

        return $this->mouse;
    }

    public function pinch(float $magnification, GesturePhase $phase): void
    {
        $this->mouse->addMagnification($magnification, $phase);
        $this->send(new Pinched($this->stage, $magnification, $phase));
    }

    public function rotate(float $degrees, GesturePhase $phase): void
    {
        $this->mouse->addRotation($degrees, $phase);
        $this->send(new Rotated($this->stage, $degrees, $phase));
    }

    public function force(float $pressure, int $stage, float $transition): void
    {
        if ([$pressure, $stage, $transition] === [$this->mouse->pressure(), $this->mouse->stage(), $this->mouse->stageTransition()]) {
            return;
        }
        $this->mouse->setForce($pressure, $stage, $transition);
        $this->send(new ForceChanged($this->stage, $pressure, $stage, $transition));
    }

    /** The application lost focus: the click's force goes with its buttons. */
    public function releaseAll(?int $at_ns = null): void
    {
        parent::releaseAll($at_ns);
        $this->force(0.0, 0, 0.0);
    }
}
