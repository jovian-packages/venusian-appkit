<?php

namespace Jovian\Toolkits\Appkit\Primitives\Concerns;

use CFData;
use CGColorSpace;
use CGDataProvider;
use CGImage;
use NSCellImagePosition;
use NSImage;
use NSSize;
use Surface\Contracts\Framebuffers\Framebuffer;
use Surface\Contracts\Windows\Primitives\ImagePosition;
use Surface\Contracts\Windows\WindowException;

/**
 * A Framebuffer as the NSImage a button shows: its RGBA8 pixels, alpha last, sized in points.
 */
trait AppkitButtonImage
{
    private static ?CGColorSpace $image_space = null;

    /** @throws WindowException When Core Graphics makes no image of the pixels. */
    protected static function nsImage(Framebuffer $image, float $scale): NSImage
    {
        $width = $image->viewportWidth();
        $height = $image->viewportHeight();
        $rgba8 = $image->toRgba8();
        self::$image_space ??= CGColorSpace::createWithName(kCGColorSpaceSRGB);
        $provider = CGDataProvider::createWithCFData(CFData::create(null, $rgba8, strlen($rgba8)));
        $cg = is_null($provider) ? null : CGImage::create(
            $width, $height, 8, 32, $width * 4, self::$image_space,
            kCGImageAlphaLast | kCGBitmapByteOrder32Big, $provider, null, false, kCGRenderingIntentDefault,
        );

        return NSImage::initWithCGImageSize(
            $cg ?? throw new WindowException("A {$width}x{$height} button image could not be made."),
            new NSSize($width / $scale, $height / $scale),
        );
    }

    protected static function cellPosition(ImagePosition $position): NSCellImagePosition
    {
        return match ($position) {
            ImagePosition::LEADING => NSCellImagePosition::IMAGE_LEADING,
            ImagePosition::ABOVE => NSCellImagePosition::IMAGE_ABOVE,
            ImagePosition::ONLY => NSCellImagePosition::IMAGE_ONLY,
        };
    }
}
