<?php

namespace Jovian\Toolkits\Appkit\Primitives\Concerns;

use NSFont;
use NSFontManager;
use NSString;
use Surface\Contracts\Windows\Styling\FontMetrics;
use Surface\Contracts\Windows\Styling\FontSpec;
use Surface\Contracts\Windows\Styling\FontWeight;

/**
 * FontSpec as an NSFont, and what AppKit measures for it: shared by the primitives and the driver.
 */
final class AppkitFonts
{
    /**
     * The system font (the monospaced system font when asked for) at the spec's size and
     * weight, or the named family at that weight through NSFontManager; a family that is not
     * installed falls back to the system font, as GTK's and Qt's font matching do. Italic is
     * the font converted to the italic trait: the system font has no family NSFontManager names.
     */
    public static function nsFont(FontSpec $font): NSFont
    {
        $made = null;
        if (! is_null($font->family)) {
            $made = NSFontManager::sharedFontManager()->fontWithFamilyTraitsWeightSize(
                $font->family, 0, self::managerWeight($font->weight), $font->size,
            );
        }
        $made ??= $font->monospace
            ? NSFont::monospacedSystemFontOfSizeWeight($font->size, self::systemWeight($font->weight))
            : NSFont::systemFontOfSizeWeight($font->size, self::systemWeight($font->weight));

        return $font->italic ? self::withTrait($made, NSItalicFontMask) : $made;
    }

    /** The font with an NSFontTraitMask trait; the font itself when no face has it. */
    public static function withTrait(NSFont $font, int $trait): NSFont
    {
        return NSFontManager::sharedFontManager()->convertFontToHaveTrait($font, $trait);
    }

    /** One space's advance in points: the unit tab stops count in. */
    public static function spaceAdvance(NSFont $font): float
    {
        return NSString::stringWithString(' ')->sizeWithAttributes([NSFontAttributeName => $font])->width;
    }

    public static function metrics(FontSpec $font): FontMetrics
    {
        $native = self::nsFont($font);
        $alphabet = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ';
        $width = NSString::stringWithString($alphabet)->sizeWithAttributes([NSFontAttributeName => $native])->width;

        return new FontMetrics(
            $native->ascender(),
            -$native->descender(),
            $native->ascender() - $native->descender() + $native->leading(),
            $width / strlen($alphabet),
            $native->isFixedPitch(),
        );
    }

    /** FontWeight on NSFontManager's 0–15 scale (5 regular, 9 bold). */
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
