<?php

namespace Jovian\Toolkits\Appkit\Menus;

use NSEventModifierFlags;
use Surface\Toolkits\Menus\Shortcut;
use Surface\Contracts\Toolkits\Menus\ShortcutKey;

/**
 * A Shortcut as AppKit has it: a key equivalent and a modifier mask.
 *
 * @internal
 */
final class AppkitShortcut
{
    /**
     * The character. A letter is lower case: Shift goes in the mask only.
     */
    public static function equivalent(Shortcut $shortcut): string
    {
        return match ($shortcut->key) {
            ShortcutKey::UP => mb_chr(NSUpArrowFunctionKey),
            ShortcutKey::DOWN => mb_chr(NSDownArrowFunctionKey),
            ShortcutKey::LEFT => mb_chr(NSLeftArrowFunctionKey),
            ShortcutKey::RIGHT => mb_chr(NSRightArrowFunctionKey),
            ShortcutKey::HOME => mb_chr(NSHomeFunctionKey),
            ShortcutKey::END => mb_chr(NSEndFunctionKey),
            ShortcutKey::PAGE_UP => mb_chr(NSPageUpFunctionKey),
            ShortcutKey::PAGE_DOWN => mb_chr(NSPageDownFunctionKey),
            ShortcutKey::DELETE => mb_chr(NSDeleteFunctionKey),
            ShortcutKey::BACKSPACE => mb_chr(NSBackspaceCharacter),
            ShortcutKey::ENTER => mb_chr(NSCarriageReturnCharacter),
            ShortcutKey::TAB => mb_chr(NSTabCharacter),
            ShortcutKey::ESCAPE => "\e",    // the SDK names no constant
            ShortcutKey::SPACE => ' ',
            ShortcutKey::F1 => mb_chr(NSF1FunctionKey),
            ShortcutKey::F2 => mb_chr(NSF2FunctionKey),
            ShortcutKey::F3 => mb_chr(NSF3FunctionKey),
            ShortcutKey::F4 => mb_chr(NSF4FunctionKey),
            ShortcutKey::F5 => mb_chr(NSF5FunctionKey),
            ShortcutKey::F6 => mb_chr(NSF6FunctionKey),
            ShortcutKey::F7 => mb_chr(NSF7FunctionKey),
            ShortcutKey::F8 => mb_chr(NSF8FunctionKey),
            ShortcutKey::F9 => mb_chr(NSF9FunctionKey),
            ShortcutKey::F10 => mb_chr(NSF10FunctionKey),
            ShortcutKey::F11 => mb_chr(NSF11FunctionKey),
            ShortcutKey::F12 => mb_chr(NSF12FunctionKey),
            default => strtolower($shortcut->key->value),
        };
    }

    public static function mask(Shortcut $shortcut): int
    {
        return ($shortcut->primary ? NSEventModifierFlags::COMMAND->value : 0)
            | ($shortcut->ctrl ? NSEventModifierFlags::CONTROL->value : 0)
            | ($shortcut->alt ? NSEventModifierFlags::OPTION->value : 0)
            | ($shortcut->shift ? NSEventModifierFlags::SHIFT->value : 0);
    }
}
