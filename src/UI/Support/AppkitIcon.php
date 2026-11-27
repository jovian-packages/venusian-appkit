<?php

namespace Jovian\Toolkits\Appkit\UI\Support;

use NSFont;
use NSSize;
use NSImage;

/**
 * An icon as topside gives it, a file and an SF Symbol name: the file when it loads, else the
 * symbol. A file is drawn at its own size in AppKit, a symbol in proportion to the font it sits
 * by, so a file is given the size a symbol has there: SYMBOL_POINTS at the system font size, in
 * proportion to the font, on its longer side.
 *
 * @internal
 */
final class AppkitIcon
{
    /** An SF Symbol's longer side at the system font size, measured: `heart` is 16 x 14 at 13 points. */
    public const float SYMBOL_POINTS = 16.0;

    public static function image(?string $file, ?string $symbol, ?string $description = null, ?NSFont $font = null): ?NSImage
    {
        $image = is_null($file) ? null : NSImage::initWithContentsOfFile($file);
        if (! is_null($image)) {
            self::fit($image, $font);

            return $image;
        }

        return is_null($symbol) ? null : NSImage::imageWithSystemSymbolNameAccessibilityDescription($symbol, $description);
    }

    /** Give an image file the size an SF Symbol has beside $font. */
    public static function fit(NSImage $image, ?NSFont $font): void
    {
        $size = $image->size();
        if ($size->width <= 0.0 || $size->height <= 0.0) {
            return;
        }

        $system = NSFont::systemFontSize();
        $side = self::SYMBOL_POINTS * ($font?->pointSize() ?? $system) / $system;
        $ratio = $size->width / $size->height;
        $image->setSize($ratio >= 1.0 ? new NSSize($side, $side / $ratio) : new NSSize($side * $ratio, $side));
    }
}
