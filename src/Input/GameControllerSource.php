<?php

namespace Jovian\Venusian\AppKit\Input;

use Closure;
use Jovian\Bindings\AppKit\GC\GCController;
use Jovian\Bindings\AppKit\GC\GCControllerAxisInput;
use Jovian\Bindings\AppKit\GC\GCControllerButtonInput;
use Jovian\Bindings\AppKit\GC\GCControllerDirectionPad;
use Jovian\Bindings\AppKit\GC\GCExtendedGamepad;
use Jovian\Bindings\AppKit\GC\GCMicroGamepad;
use Jovian\Bindings\AppKit\Runtime\Bridge;
use Jovian\Bindings\AppKit\Runtime\ObjCObject;
use Jovian\Venusian\AppKit\Exceptions\AppKitInputException;
use Surface\Contracts\HumanInput\GamepadAxis;
use Surface\Contracts\HumanInput\GamepadButton;

/**
 * GameController.framework as a GamepadSource. The controller boxes and
 * their profile element boxes are held while the controller is attached, so
 * a read is one ext call per element. Background monitoring is switched on
 * so pads read while no app window is key.
 */
final class GameControllerSource implements GamepadSource
{
    /** @var array<int, GCController> attached controllers by handle */
    private array $controllers = [];

    /**
     * @var array<int, array{buttons: array<string, GCControllerButtonInput>, axes: array<string, GCControllerAxisInput|GCControllerButtonInput>}>
     *      each controller's profile elements, keyed by GamepadButton / GamepadAxis value
     */
    private array $profiles = [];

    /** @var list<int> notification observer tokens */
    private array $observers = [];

    public function handles(): array
    {
        $attached = [];

        foreach (GCController::controllers() as $handle) {
            $handle = (int) $handle;
            $controller = $this->controllers[$handle] ?? ObjCObject::box($handle);

            if ($controller instanceof GCController) {
                $attached[$handle] = $controller;
            }
        }

        $this->controllers = $attached;
        $this->profiles = array_intersect_key($this->profiles, $attached);

        return array_keys($attached);
    }

    public function name(int $controller): string
    {
        $pad = $this->controller($controller);
        $vendor = $pad->vendorName();

        if (is_string($vendor) && $vendor !== '') {
            return $vendor;
        }

        return $pad->productCategory() ?: 'Game Controller';
    }

    public function extended(int $controller): bool
    {
        return $this->controller($controller)->extendedGamepad() instanceof GCExtendedGamepad;
    }

    public function buttons(int $controller): array
    {
        $down = [];

        foreach ($this->profile($controller)['buttons'] as $button => $input) {
            $down[$button] = $input->isPressed();
        }

        return $down;
    }

    public function axes(int $controller): array
    {
        $values = [];

        foreach ($this->profile($controller)['axes'] as $axis => $input) {
            $values[$axis] = $input->value();
        }

        return self::yDown($values);
    }

    public function onChange(Closure $changed): void
    {
        $this->removeObservers();
        GCController::setShouldMonitorBackgroundEvents(true);

        $notify = static function (?ObjCObject $object, string $name) use ($changed): void {
            $changed();
        };

        foreach (['GCControllerDidConnectNotification', 'GCControllerDidDisconnectNotification'] as $name) {
            $this->observers[] = Bridge::observeNotification(0, $name, $notify);
        }
    }

    public function stop(): void
    {
        $this->removeObservers();
        $this->controllers = [];
        $this->profiles = [];
    }

    /**
     * @internal GameController reports sticks y-up; Surface reads them y-down.
     *
     * @param array<string, float> $axes keyed by GamepadAxis->value
     * @return array<string, float>
     */
    public static function yDown(array $axes): array
    {
        foreach ([GamepadAxis::LEFT_Y->value, GamepadAxis::RIGHT_Y->value] as $axis) {
            if (array_key_exists($axis, $axes)) {
                $axes[$axis] = -$axes[$axis];
            }
        }

        return $axes;
    }

    private function controller(int $handle): GCController
    {
        if (! array_key_exists($handle, $this->controllers)) {
            $controller = ObjCObject::box($handle);

            if (! $controller instanceof GCController) {
                throw AppKitInputException::noSuchController($handle);
            }

            $this->controllers[$handle] = $controller;
        }

        return $this->controllers[$handle];
    }

    /** @return array{buttons: array<string, GCControllerButtonInput>, axes: array<string, GCControllerAxisInput|GCControllerButtonInput>} */
    private function profile(int $handle): array
    {
        if (array_key_exists($handle, $this->profiles)) {
            return $this->profiles[$handle];
        }

        $controller = $this->controller($handle);
        $extended = $controller->extendedGamepad();

        if ($extended instanceof GCExtendedGamepad) {
            $left = $extended->leftThumbstick();
            $right = $extended->rightThumbstick();
            $buttons = [
                GamepadButton::SOUTH->value => $extended->buttonA(),
                GamepadButton::EAST->value => $extended->buttonB(),
                GamepadButton::WEST->value => $extended->buttonX(),
                GamepadButton::NORTH->value => $extended->buttonY(),
                GamepadButton::LEFT_SHOULDER->value => $extended->leftShoulder(),
                GamepadButton::RIGHT_SHOULDER->value => $extended->rightShoulder(),
                GamepadButton::LEFT_STICK->value => $extended->leftThumbstickButton(),
                GamepadButton::RIGHT_STICK->value => $extended->rightThumbstickButton(),
                GamepadButton::START->value => $extended->buttonMenu(),
                GamepadButton::BACK->value => $extended->buttonOptions(),
                GamepadButton::GUIDE->value => $extended->buttonHome(),
                ...self::dpadButtons($extended->dpad()),
            ];
            $axes = [
                GamepadAxis::LEFT_X->value => $left instanceof GCControllerDirectionPad ? $left->xAxis() : null,
                GamepadAxis::LEFT_Y->value => $left instanceof GCControllerDirectionPad ? $left->yAxis() : null,
                GamepadAxis::RIGHT_X->value => $right instanceof GCControllerDirectionPad ? $right->xAxis() : null,
                GamepadAxis::RIGHT_Y->value => $right instanceof GCControllerDirectionPad ? $right->yAxis() : null,
                GamepadAxis::LEFT_TRIGGER->value => $extended->leftTrigger(),
                GamepadAxis::RIGHT_TRIGGER->value => $extended->rightTrigger(),
            ];
        } else {
            $micro = $controller->microGamepad();
            $buttons = $micro instanceof GCMicroGamepad ? [
                GamepadButton::SOUTH->value => $micro->buttonA(),
                GamepadButton::WEST->value => $micro->buttonX(),
                GamepadButton::START->value => $micro->buttonMenu(),
                ...self::dpadButtons($micro->dpad()),
            ] : [];
            $axes = [];
        }

        return $this->profiles[$handle] = [
            'buttons' => array_filter($buttons, static fn (?ObjCObject $input): bool => $input instanceof GCControllerButtonInput),
            'axes' => array_filter($axes, static fn (?ObjCObject $input): bool => $input instanceof GCControllerAxisInput || $input instanceof GCControllerButtonInput),
        ];
    }

    /** @return array<string, ?ObjCObject> */
    private static function dpadButtons(?ObjCObject $dpad): array
    {
        if (! $dpad instanceof GCControllerDirectionPad) {
            return [];
        }

        return [
            GamepadButton::DPAD_UP->value => $dpad->up(),
            GamepadButton::DPAD_DOWN->value => $dpad->down(),
            GamepadButton::DPAD_LEFT->value => $dpad->left(),
            GamepadButton::DPAD_RIGHT->value => $dpad->right(),
        ];
    }

    private function removeObservers(): void
    {
        foreach ($this->observers as $token) {
            Bridge::removeObserver($token);
        }

        $this->observers = [];
    }
}
