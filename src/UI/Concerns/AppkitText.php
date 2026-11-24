<?php

namespace Jovian\Toolkits\Appkit\UI\Concerns;

use NSFont;
use NSFontManager;
use NSTextAlignment;
use Surface\Contracts\Toolkits\Styling\FontSpec;
use Surface\Contracts\Toolkits\Styling\FontWeight;
use Surface\Contracts\Toolkits\Styling\TextAlignment;

/**
 * Fonts and alignment, for every AppKit primitive that shows text.
 */
trait AppkitText
{
    protected static function nsAlignment(TextAlignment $alignment): NSTextAlignment
    {
        return match ($alignment) {
            TextAlignment::LEFT => NSTextAlignment::LEFT,
            TextAlignment::CENTER => NSTextAlignment::CENTER,
            TextAlignment::RIGHT => NSTextAlignment::RIGHT,
        };
    }

    /**
     * The system font (or its monospaced face) at the size and weight; a named family at that
     * weight through NSFontManager, falling back to the system font when it is not installed.
     * Italic converts the font to the italic trait.
     */
    protected static function nsFont(FontSpec $font): NSFont
    {
        $made = null;
        if (! is_null($font->family)) {
            $made = NSFontManager::sharedFontManager()->fontWithFamilyTraitsWeightSize($font->family, 0, self::managerWeight($font->weight), $font->size);
        }
        $made ??= $font->monospace
            ? NSFont::monospacedSystemFontOfSizeWeight($font->size, self::systemWeight($font->weight))
            : NSFont::systemFontOfSizeWeight($font->size, self::systemWeight($font->weight));

        if ($font->italic) {
            $made = NSFontManager::sharedFontManager()->convertFontToHaveTrait($made, NSItalicFontMask);
        }

        return $made;
    }

    /**
     * NSFontManager's 0–15 scale (5 regular, 9 bold).
     */
    private static function managerWeight(FontWeight $weight): int
    {
        return match ($weight) {
            FontWeight::LIGHT => 3,
            FontWeight::REGULAR => 5,
            FontWeight::MEDIUM => 6,
            FontWeight::SEMIBOLD => 8,
            FontWeight::BOLD => 9,
            FontWeight::BLACK => 11,
        };
    }

    private static function systemWeight(FontWeight $weight): float
    {
        return match ($weight) {
            FontWeight::LIGHT => NSFont::WEIGHT_LIGHT,
            FontWeight::REGULAR => NSFont::WEIGHT_REGULAR,
            FontWeight::MEDIUM => NSFont::WEIGHT_MEDIUM,
            FontWeight::SEMIBOLD => NSFont::WEIGHT_SEMIBOLD,
            FontWeight::BOLD => NSFont::WEIGHT_BOLD,
            FontWeight::BLACK => NSFont::WEIGHT_BLACK,
        };
    }
}
