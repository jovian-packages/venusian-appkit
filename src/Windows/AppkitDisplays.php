<?php

namespace Jovian\Toolkits\Appkit\Windows;

use CGDisplay;
use CGDisplayMode;
use NSRect;
use NSScreen;
use Surface\Contracts\Framebuffers\Region;
use Surface\Contracts\Windows\Display;
use Surface\Contracts\Windows\DisplayMode;
use Surface\Contracts\Windows\Hdr;
use Surface\Contracts\Windows\WindowException;

/**
 * Displays as Surface sees them, from NSScreen and CGDirectDisplay. A display's
 * id is its CGDirectDisplayID. Desktop coordinates put 0,0 at the top-left of
 * the menu-bar screen, y down; Cocoa's put it at that screen's bottom-left, y up.
 *
 * While a staged window holds a display in exclusive fullscreen, the mode the
 * display ran before stays here as its desktop mode.
 */
final class AppkitDisplays
{
    /** @var array<int, CGDisplayMode> display id → the mode it ran before exclusive fullscreen */
    private static array $desktop = [];

    /** @return list<Display> the menu-bar screen first */
    public static function all(): array
    {
        return array_map(fn (NSScreen $screen): Display => self::describe($screen), NSScreen::screens());
    }

    public static function primary(): Display
    {
        return self::describe(NSScreen::screens()[0] ?? throw new WindowException('macOS reports no screen.'));
    }

    public static function describe(NSScreen $screen): Display
    {
        $id = self::id($screen);
        $current = self::mode($id, CGDisplay::copyDisplayMode($id), $screen);
        $desktop = isset(self::$desktop[$id]) ? self::mode($id, self::$desktop[$id], $screen) : $current;
        $potential = $screen->maximumPotentialExtendedDynamicRangeColorComponentValue();
        $headroom = $screen->maximumExtendedDynamicRangeColorComponentValue();

        return new Display(
            id: $id,
            name: $screen->localizedName(),
            bounds: self::toDesktop($screen->frame()),
            usable: self::toDesktop($screen->visibleFrame()),
            scale: $screen->backingScaleFactor(),
            current: $current,
            desktop: $desktop,
            hdr: new Hdr($potential > 1.0, 1.0, $headroom),
        );
    }

    /** The screen for a CGDirectDisplayID; null when no screen has it. */
    public static function screen(int $id): ?NSScreen
    {
        foreach (NSScreen::screens() as $screen) {
            if (self::id($screen) === $id) {
                return $screen;
            }
        }

        return null;
    }

    public static function id(NSScreen $screen): int
    {
        return (int) ($screen->deviceDescription()['NSScreenNumber'] ?? 0);
    }

    /**
     * Every mode the display offers a desktop at, HiDPI ones included, each once.
     *
     * @return list<DisplayMode>
     */
    public static function modes(int $id): array
    {
        $screen = self::screen($id);
        $modes = [];
        foreach (CGDisplay::copyAllDisplayModes($id, [kCGDisplayShowDuplicateLowResolutionModes => true]) as $native) {
            if (! $native->isUsableForDesktopGUI()) {
                continue;
            }
            $mode = self::mode($id, $native, $screen);
            $modes["{$mode->width}x{$mode->height}@{$mode->pixelDensity}/{$mode->refreshRate}"] = $mode;
        }

        return array_values($modes);
    }

    /** The native mode a DisplayMode names; null when the display offers none like it. */
    public static function nativeMode(DisplayMode $mode): ?CGDisplayMode
    {
        $screen = self::screen($mode->displayId);
        foreach (CGDisplay::copyAllDisplayModes($mode->displayId, [kCGDisplayShowDuplicateLowResolutionModes => true]) as $native) {
            if (self::mode($mode->displayId, $native, $screen)->equals($mode)) {
                return $native;
            }
        }

        return null;
    }

    /** Remember the mode $id runs now as its desktop mode, before exclusive fullscreen changes it. */
    public static function holdDesktop(int $id): void
    {
        self::$desktop[$id] ??= CGDisplay::copyDisplayMode($id) ?? throw new WindowException("Display {$id} reports no mode.");
    }

    /**
     * Put $id back on its desktop mode and forget it; nothing when none is held.
     *
     * @throws WindowException When Core Graphics refuses the mode; the desktop mode stays held.
     */
    public static function restoreDesktop(int $id): void
    {
        if (! isset(self::$desktop[$id])) {
            return;
        }
        $error = CGDisplay::setDisplayMode($id, self::$desktop[$id], null);
        if ($error !== kCGErrorSuccess) {
            throw new WindowException("Display {$id}: CGDisplaySetDisplayMode failed with CGError {$error}.");
        }
        unset(self::$desktop[$id]);
    }

    /** A Cocoa rect as a desktop region in points. */
    public static function toDesktop(NSRect $rect): Region
    {
        $top = self::primaryHeight() - ($rect->y + $rect->height);

        return new Region((int) round($rect->x), (int) round($top), (int) round($rect->width), (int) round($rect->height));
    }

    /** A desktop rect as a Cocoa rect. */
    public static function toCocoa(int $x, int $y, float $width, float $height): NSRect
    {
        return new NSRect((float) $x, self::primaryHeight() - $y - $height, $width, $height);
    }

    /** A CGDirectDisplay rect (top-left origin already) as a Cocoa rect, read after a mode change. */
    public static function displayRect(int $id): NSRect
    {
        $bounds = CGDisplay::bounds($id);
        $primary = CGDisplay::bounds(CGDisplay::mainDisplayID());

        return new NSRect($bounds->x, $primary->height - $bounds->y - $bounds->height, $bounds->width, $bounds->height);
    }

    private static function primaryHeight(): float
    {
        return CGDisplay::bounds(CGDisplay::mainDisplayID())->height;
    }

    /** A display whose mode reports no refresh rate (a built-in panel) runs at its screen's maximum. */
    private static function mode(int $id, ?CGDisplayMode $native, ?NSScreen $screen): DisplayMode
    {
        if (is_null($native)) {
            throw new WindowException("Display {$id} reports no mode.");
        }
        $rate = $native->getRefreshRate();
        if ($rate <= 0.0) {
            $rate = (float) ($screen?->maximumFramesPerSecond() ?: 60);
        }

        return new DisplayMode($id, $native->getWidth(), $native->getHeight(), $native->getPixelWidth() / max(1, $native->getWidth()), $rate);
    }
}
