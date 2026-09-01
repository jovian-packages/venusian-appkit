<?php

namespace Jovian\Venusian\AppKit\Views;

use Jovian\Bindings\AppKit\NS\NSColor;
use Jovian\Bindings\AppKit\NS\NSFont;
use Jovian\Bindings\AppKit\Runtime\ObjCObject;
use Surface\Contracts\NativeWindows\Views\Color;
use Surface\Contracts\NativeWindows\Views\FontSpec;
use Surface\Contracts\NativeWindows\Views\FontWeight;
use Surface\Contracts\NativeWindows\WindowableException;

/**
 * Turns Surface style values into AppKit objects. The boxed NSColor/NSFont
 * are returned live; callers use the handle immediately — anything AppKit
 * needs to keep, AppKit retains itself.
 */
trait ComposesAppKitStyle
{
    protected function nsColor(Color $color): ObjCObject
    {
        $ns = NSColor::colorWithSRGBRedGreenBlueAlpha($color->red, $color->green, $color->blue, $color->alpha);
        if (is_null($ns)) {
            throw new WindowableException('AppKit did not mint an NSColor.');
        }

        return $ns;
    }

    protected function nsFont(FontSpec $font): ObjCObject
    {
        $ns = is_null($font->family)
            ? NSFont::systemFontOfSizeWeight($font->size, $this->nsFontWeight($font->weight))
            : NSFont::fontWithNameSize($font->family, $font->size);
        if (is_null($ns)) {
            throw new WindowableException("AppKit did not mint a font for '{$font->family}'.");
        }

        return $ns;
    }

    /** Apple's NSFontWeight constants, by value — the enum cases are not bound. */
    protected function nsFontWeight(FontWeight $weight): float
    {
        return match ($weight) {
            FontWeight::LIGHT => -0.4,
            FontWeight::REGULAR => 0.0,
            FontWeight::MEDIUM => 0.23,
            FontWeight::SEMIBOLD => 0.3,
            FontWeight::BOLD => 0.4,
            FontWeight::BLACK => 0.62,
        };
    }
}
