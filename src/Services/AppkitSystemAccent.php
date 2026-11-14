<?php

namespace Jovian\Toolkits\Appkit\Services;

use NSColor;
use NSColorSpace;
use Surface\Contracts\Toolkits\Services\SystemAccent;
use Surface\Contracts\Toolkits\ToolkitRuntimeException;
use Surface\NutsAndBolts\Color;

/** The accent of System Settings (NSColor controlAccentColor), in sRGB; macOS always has one. */
final class AppkitSystemAccent implements SystemAccent
{
    /** @throws ToolkitRuntimeException When AppKit cannot give the accent in sRGB. */
    public function color(): ?Color
    {
        $srgb = NSColor::controlAccentColor()->colorUsingColorSpace(NSColorSpace::sRGBColorSpace())
            ?? throw new ToolkitRuntimeException('AppKit cannot give controlAccentColor in sRGB.');

        return new Color($srgb->redComponent(), $srgb->greenComponent(), $srgb->blueComponent(), 1.0);
    }
}
