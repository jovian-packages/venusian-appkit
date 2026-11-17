<?php

namespace Jovian\Toolkits\Appkit\Input;

use Surface\HumanInput\Devices\Mouse;

/**
 * The pointer as AppKit reports it: a mouse, a Magic Mouse or a trackpad. AppKit does not say
 * which device an event came from, so they are one class, read by what they report: a mouse never
 * pinches, rotates or force-clicks, and those read 0 until a device that does.
 *
 * Pinch and rotation add up over the frame (magnification(), rotation()) and over the gesture
 * (pinchTotal(), rotationTotal()), which starts again as a new gesture begins. Force is a level:
 * pressure() 0…1 within the click stage, stage() 0 (not clicked), 1 (clicked) or 2 (force
 * clicked), stageTransition() how far toward the next stage (positive) or back (negative).
 */
class AppleMouse extends Mouse
{
    protected float $magnification = 0.0;

    protected float $pinchTotal = 0.0;

    protected GesturePhase $pinchPhase = GesturePhase::NONE;

    protected float $rotation = 0.0;

    protected float $rotationTotal = 0.0;

    protected GesturePhase $rotationPhase = GesturePhase::NONE;

    protected float $pressure = 0.0;

    protected int $stage = 0;

    protected float $stageTransition = 0.0;

    /** One pinch step: the change in scale, positive spreading the fingers. */
    public function addMagnification(float $delta, GesturePhase $phase): void
    {
        if ($phase === GesturePhase::BEGAN) {
            $this->pinchTotal = 0.0;
        }
        $this->magnification += $delta;
        $this->pinchTotal += $delta;
        $this->pinchPhase = $phase;
    }

    /** One rotation step, in degrees, positive counterclockwise. */
    public function addRotation(float $degrees, GesturePhase $phase): void
    {
        if ($phase === GesturePhase::BEGAN) {
            $this->rotationTotal = 0.0;
        }
        $this->rotation += $degrees;
        $this->rotationTotal += $degrees;
        $this->rotationPhase = $phase;
    }

    public function setForce(float $pressure, int $stage, float $transition): void
    {
        $this->pressure = $pressure;
        $this->stage = $stage;
        $this->stageTransition = $transition;
    }

    public function settle(): void
    {
        parent::settle();
        $this->magnification = $this->rotation = 0.0;
        // a gesture under way stays in its phase; an ended one is over
        if ($this->pinchPhase === GesturePhase::ENDED) {
            $this->pinchPhase = GesturePhase::NONE;
        }
        if ($this->rotationPhase === GesturePhase::ENDED) {
            $this->rotationPhase = GesturePhase::NONE;
        }
    }

    /** The pointer's buttons went up with the app's focus: so did the click. */
    public function releaseAll(?int $at_ns = null): void
    {
        parent::releaseAll($at_ns);
        $this->setForce(0.0, 0, 0.0);
    }

    public function magnification(): float
    {
        return $this->magnification;
    }

    public function pinchTotal(): float
    {
        return $this->pinchTotal;
    }

    public function pinchPhase(): GesturePhase
    {
        return $this->pinchPhase;
    }

    public function rotation(): float
    {
        return $this->rotation;
    }

    public function rotationTotal(): float
    {
        return $this->rotationTotal;
    }

    public function rotationPhase(): GesturePhase
    {
        return $this->rotationPhase;
    }

    public function pressure(): float
    {
        return $this->pressure;
    }

    public function stage(): int
    {
        return $this->stage;
    }

    public function stageTransition(): float
    {
        return $this->stageTransition;
    }
}
