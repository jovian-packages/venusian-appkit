<?php

namespace Jovian\Toolkits\Appkit\Services;

use NSColor;
use NSColorSpace;
use Surface\Contracts\Windows\Services\SystemAccent;
use Surface\Contracts\Windows\WindowException;
use Surface\NutsAndBolts\Color;

/** The accent of System Settings (NSColor controlAccentColor), in sRGB; macOS always has one. */
final class AppkitSystemAccent implements SystemAccent
{
    /** @throws WindowException When AppKit cannot give the accent in sRGB. */
    public function color(): ?Color
    {
        $srgb = NSColor::controlAccentColor()->colorUsingColorSpace(NSColorSpace::sRGBColorSpace())
            ?? throw new WindowException('AppKit cannot give controlAccentColor in sRGB.');

        return new Color($srgb->redComponent(), $srgb->greenComponent(), $srgb->blueComponent(), 1.0);
    }
}
