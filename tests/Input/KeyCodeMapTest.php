<?php

use Jovian\Venusian\AppKit\Input\KeyCodeMap;
use Surface\Contracts\HumanInput\Key;

it('maps macOS virtual key codes to Surface keys', function (int $key_code, Key $key) {
    expect(KeyCodeMap::key($key_code))->toBe($key);
})->with([
    'a' => [0x00, Key::A],
    's' => [0x01, Key::S],
    'w' => [0x0D, Key::W],
    'digit 1' => [0x12, Key::DIGIT_1],
    'digit 6 before 5' => [0x16, Key::DIGIT_6],
    'digit 5 after 6' => [0x17, Key::DIGIT_5],
    'digit 0' => [0x1D, Key::DIGIT_0],
    'return' => [0x24, Key::ENTER],
    'escape' => [0x35, Key::ESCAPE],
    'space' => [0x31, Key::SPACE],
    'left shift' => [0x38, Key::LEFT_SHIFT],
    'right shift' => [0x3C, Key::RIGHT_SHIFT],
    'left command' => [0x37, Key::LEFT_META],
    'right command' => [0x36, Key::RIGHT_META],
    'right control' => [0x3E, Key::RIGHT_CTRL],
    'caps lock' => [0x39, Key::CAPS_LOCK],
    'left arrow' => [0x7B, Key::LEFT],
    'up arrow' => [0x7E, Key::UP],
    'numpad 0' => [0x52, Key::NUMPAD_0],
    'numpad 8 after the gap' => [0x5B, Key::NUMPAD_8],
    'numpad enter' => [0x4C, Key::NUMPAD_ENTER],
    'f1' => [0x7A, Key::F1],
    'f12' => [0x6F, Key::F12],
    'forward delete' => [0x75, Key::DELETE],
]);

it('answers UNKNOWN for codes it has no key for', function (int $key_code) {
    expect(KeyCodeMap::key($key_code))->toBe(Key::UNKNOWN);
})->with([
    'unassigned 0x34' => [0x34],
    'section sign 0x0A' => [0x0A],
    'past the table' => [0x200],
    'negative' => [-1],
]);
