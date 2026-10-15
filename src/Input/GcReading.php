<?php

namespace Jovian\Toolkits\Appkit\Input;

use Closure;
use GCController;
use GCControllerButtonInput;
use GCControllerPlayerIndex;
use Surface\Contracts\HumanInput\GamepadAxis;
use Surface\Contracts\HumanInput\GamepadButton;

/**
 * One GameController pad as it reads now: its id (`gc-<NSObject::hash()>`, stable while it
 * stays connected), name, the buttons it has by GamepadButton value, its axes by GamepadAxis
 * value (none for a pad without sticks), and what lights its player LEDs. Sticks read y
 * down-positive, as Surface's do (GameController's y is up-positive); triggers 0 to 1.
 */
final readonly class GcReading
{
    /**
     * @param array<string, bool> $buttons
     * @param array<string, float> $axes
     * @param (Closure(?int): void)|null $lights
     */
    public function __construct(
        public string $id,
        public string $name,
        public array $buttons,
        public array $axes,
        public ?Closure $lights,
    ) {}

    /** The GameController player index for Surface's: 0 to 3 light the first to fourth; anything else, and null, unset it. */
    public static function playerIndex(?int $index): GCControllerPlayerIndex
    {
        return ! is_null($index) && $index >= 0 && $index <= 3 ? GCControllerPlayerIndex::from($index) : GCControllerPlayerIndex::UNSET;
    }

    /** The reading of $controller, or null for one with neither an extended nor a micro gamepad. */
    public static function of(GCController $controller): ?self
    {
        $id = 'gc-'.$controller->hash();
        $name = $controller->vendorName() ?? 'Game controller';
        $lights = static function (?int $index) use ($controller): void {
            $controller->setPlayerIndex(self::playerIndex($index));
        };

        $pad = $controller->extendedGamepad();
        if (! is_null($pad)) {
            $buttons = [
                GamepadButton::SOUTH->value => $pad->buttonA()->isPressed(),
                GamepadButton::EAST->value => $pad->buttonB()->isPressed(),
                GamepadButton::WEST->value => $pad->buttonX()->isPressed(),
                GamepadButton::NORTH->value => $pad->buttonY()->isPressed(),
                GamepadButton::LEFT_SHOULDER->value => $pad->leftShoulder()->isPressed(),
                GamepadButton::RIGHT_SHOULDER->value => $pad->rightShoulder()->isPressed(),
                GamepadButton::START->value => $pad->buttonMenu()->isPressed(),
                GamepadButton::DPAD_UP->value => $pad->dpad()->up()->isPressed(),
                GamepadButton::DPAD_DOWN->value => $pad->dpad()->down()->isPressed(),
                GamepadButton::DPAD_LEFT->value => $pad->dpad()->left()->isPressed(),
                GamepadButton::DPAD_RIGHT->value => $pad->dpad()->right()->isPressed(),
            ];
            $optional = [
                GamepadButton::BACK->value => $pad->buttonOptions(),
                GamepadButton::GUIDE->value => $pad->buttonHome(),
                GamepadButton::LEFT_STICK->value => $pad->leftThumbstickButton(),
                GamepadButton::RIGHT_STICK->value => $pad->rightThumbstickButton(),
            ];
            foreach (array_filter($optional, fn (?GCControllerButtonInput $button): bool => ! is_null($button)) as $button => $input) {
                $buttons[$button] = $input->isPressed();
            }

            return new self($id, $name, $buttons, [
                GamepadAxis::LEFT_X->value => $pad->leftThumbstick()->xAxis()->value(),
                GamepadAxis::LEFT_Y->value => -$pad->leftThumbstick()->yAxis()->value(),
                GamepadAxis::RIGHT_X->value => $pad->rightThumbstick()->xAxis()->value(),
                GamepadAxis::RIGHT_Y->value => -$pad->rightThumbstick()->yAxis()->value(),
                GamepadAxis::LEFT_TRIGGER->value => $pad->leftTrigger()->value(),
                GamepadAxis::RIGHT_TRIGGER->value => $pad->rightTrigger()->value(),
            ], $lights);
        }

        $micro = $controller->microGamepad();
        if (is_null($micro)) {
            return null;
        }

        return new self($id, $name, [
            GamepadButton::SOUTH->value => $micro->buttonA()->isPressed(),
            GamepadButton::WEST->value => $micro->buttonX()->isPressed(),
            GamepadButton::START->value => $micro->buttonMenu()->isPressed(),
            GamepadButton::DPAD_UP->value => $micro->dpad()->up()->isPressed(),
            GamepadButton::DPAD_DOWN->value => $micro->dpad()->down()->isPressed(),
            GamepadButton::DPAD_LEFT->value => $micro->dpad()->left()->isPressed(),
            GamepadButton::DPAD_RIGHT->value => $micro->dpad()->right()->isPressed(),
        ], [], $lights);
    }
}
