<?php

namespace Jovian\Toolkits\Appkit\Primitives;

use CFData;
use CGColorSpace;
use CGDataProvider;
use CGImage;
use Jovian\Toolkits\Appkit\Primitives\Concerns\AppkitPrimitive;
use NSRect;
use NSView;
use Surface\Contracts\Windows\Primitives\Placement;
use Surface\Contracts\Windows\ToolkitWindow;
use Surface\Contracts\Windows\WindowException;
use Surface\Windows\Primitives\TKCanvas;
use Surface\Windows\Primitives\TKPrimitiveGroup;

/**
 * A layer-backed view whose layer contents are the canvas's pixels: each present() wraps the
 * framebuffer's RGBA8 bytes in a CGImage (sRGB, the fourth byte skipped, so opaque) and sets it
 * as the contents, stretched over the bounds. The view has no natural size: the layout sizes it.
 */
class AppkitCanvas extends TKCanvas
{
    use AppkitPrimitive;

    protected static ?CGColorSpace $space = null;

    public function __construct(string $name, ToolkitWindow $window, ?TKPrimitiveGroup $parent, Placement $placement)
    {
        parent::__construct($name, $window, $parent, $placement);
        $view = $this->adoptNative(NSView::initWithFrame(new NSRect()));
        $view->setLayerMasksToBounds(true);
        $view->setLayerContentsGravity(kCAGravityResize);
    }

    /** The window's backing scale: 2.0 on a Retina display. */
    protected function nativeScale(): float
    {
        return $this->host()->native()->backingScaleFactor();
    }

    /**
     * @throws WindowException When Core Graphics refuses the image.
     */
    protected function applyPixels(string $rgba8, int $width, int $height): void
    {
        self::$space ??= CGColorSpace::createWithName(kCGColorSpaceSRGB);
        $provider = CGDataProvider::createWithCFData(CFData::create($rgba8));
        $image = is_null($provider) ? null : CGImage::create(
            $width, $height, 8, 32, $width * 4, self::$space,
            kCGImageAlphaNoneSkipLast | kCGBitmapByteOrder32Big, $provider, true, kCGRenderingIntentDefault,
        );
        if (is_null($image)) {
            throw new WindowException("Canvas '{$this->path()}' could not show a {$width}x{$height} image.");
        }

        $this->native->setLayerContents($image);
    }
}
