<?php

namespace Jovian\Toolkits\Appkit\Primitives;

use CFData;
use CGColorSpace;
use CGDataProvider;
use CGImage;
use Jovian\Toolkits\Appkit\Primitives\Concerns\AppkitPrimitive;
use Jovian\Toolkits\Appkit\Windows\Concerns\LendsAppkitSurfaces;
use NSRect;
use NSView;
use NSWindow;
use Surface\Contracts\Windows\Primitives\Placement;
use Surface\Contracts\Windows\ToolkitWindow;
use Surface\Contracts\Windows\WindowException;
use Surface\Windows\Primitives\TKCanvas;
use Surface\Windows\Primitives\TKPrimitiveGroup;

/**
 * A layer-backed view whose layer contents are the canvas's pixels: each present() wraps the
 * framebuffer's RGBA8 bytes in a CGImage (sRGB, the fourth byte skipped, so opaque) and sets it
 * as the contents, stretched over the bounds. An ext-fb framebuffer never becomes a string:
 * its memory is copied by address into the CFData the image reads. The view has no natural
 * size: the layout sizes it.
 *
 * A GPU engine borrows the view's layer instead: a CAMetalLayer from ext-metal, set as the
 * layer of the view while lent (WindowOutput); reclaimed, the view gets a backing layer of its
 * own back and shows images again.
 *
 * With ext-opengl loaded it lends a GL context: an NSOpenGLView (OpenGL 4.1 core, double
 * buffered, at the backing resolution) pinned over the view. present() marks it for display,
 * and its drawRect: copies the borrower's frame into framebuffer 0 and flushes, so a redraw
 * AppKit asks for on its own copies the last frame again.
 */
class AppkitCanvas extends TKCanvas
{
    use AppkitPrimitive;
    use LendsAppkitSurfaces;

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
    protected function applyPixels(string $rgba8, int $width, int $height, array $damage): void
    {
        $this->showData(CFData::create($rgba8), $width, $height);
    }

    protected function applyAddress(int $address, int $width, int $height, int $stride, array $damage): void
    {
        $this->showData(CFData::create($address, $stride * $height), $width, $height);
    }

    protected function lendingView(): NSView
    {
        return $this->native;
    }

    protected function lendingWindow(): NSWindow
    {
        return $this->host()->native();
    }

    /** A backing layer of the view's own again, set up as the constructor set the first. */
    protected function restoreOwnLayer(): void
    {
        $this->native->setLayerMasksToBounds(true);
        $this->native->setLayerContentsGravity(kCAGravityResize);
    }

    /** Parked SDL windows whose device has let go are destroyed first. */
    public function present(): static
    {
        self::sweepSdlWindows();

        return parent::present();
    }

    /**
     * The image over $data as the layer's contents.
     *
     * @throws WindowException When Core Graphics refuses the image.
     */
    private function showData(CFData $data, int $width, int $height): void
    {
        self::$space ??= CGColorSpace::createWithName(kCGColorSpaceSRGB);
        $provider = CGDataProvider::createWithCFData($data);
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
