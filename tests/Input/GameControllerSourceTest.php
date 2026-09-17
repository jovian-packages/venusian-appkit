<?php

use Jovian\Venusian\AppKit\Input\GameControllerSource;
use Surface\Contracts\HumanInput\GamepadAxis;

it('negates both stick Y axes from GameController y-up to Surface y-down', function () {
    expect(GameControllerSource::yDown([
        GamepadAxis::LEFT_X->value => 0.25,
        GamepadAxis::LEFT_Y->value => 0.5,
        GamepadAxis::RIGHT_X->value => -0.75,
        GamepadAxis::RIGHT_Y->value => -1.0,
        GamepadAxis::LEFT_TRIGGER->value => 0.4,
        GamepadAxis::RIGHT_TRIGGER->value => 1.0,
    ]))->toBe([
        GamepadAxis::LEFT_X->value => 0.25,
        GamepadAxis::LEFT_Y->value => -0.5,
        GamepadAxis::RIGHT_X->value => -0.75,
        GamepadAxis::RIGHT_Y->value => 1.0,
        GamepadAxis::LEFT_TRIGGER->value => 0.4,
        GamepadAxis::RIGHT_TRIGGER->value => 1.0,
    ]);
});

it('leaves a profile with no sticks untouched', function () {
    expect(GameControllerSource::yDown([]))->toBe([])
        ->and(GameControllerSource::yDown([GamepadAxis::LEFT_TRIGGER->value => 0.5]))->toBe([GamepadAxis::LEFT_TRIGGER->value => 0.5]);
});
