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
use ObjCFocusView;
use Surface\Contracts\Windows\Primitives\Placement;
use Surface\Contracts\Windows\ToolkitWindow;
use Surface\Contracts\Windows\WindowException;
use Surface\Windows\Primitives\TKCanvas;
use Surface\Windows\Primitives\TKPrimitiveGroup;
use WeakReference;

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
        $view = $this->adoptNative(ObjCFocusView::initWithFrame(new NSRect(0.0, 0.0, 0.0, 0.0)));
        $view->setWantsLayer(true);
        $view->layer()?->setMasksToBounds(true);
        $view->layer()?->setContentsGravity(kCAGravityResize);
        // The view holds the handler, and this canvas the view: a weak reference breaks the cycle.
        $self = WeakReference::create($this);
        $view->on('acceptsFirstResponder', static fn (): bool => $self->get()?->acceptsFocus() ?? false);
    }

    /** The enabled state the user sees: this canvas's own, unless a container above it is disabled. */
    private bool $native_enabled = true;

    /** What the focus view answers AppKit's acceptsFirstResponder: focusable and enabled, as GTK and Qt give a disabled widget no focus. */
    public function acceptsFocus(): bool
    {
        return $this->isFocusable() && $this->native_enabled;
    }

    /** A disabled canvas lets go of focus; the window's applyEnabled runs this quietly. */
    protected function applyNativeEnabled(bool $on): void
    {
        $this->native_enabled = $on;
        $window = $this->host()->native();
        if (! $on && $window->firstResponder() === $this->native) {
            $window->makeFirstResponder(null);
        }
    }

    /** The focus view's own handler reads the flag; a canvas that stops taking focus while it has it lets go, quietly. */
    protected function applyFocusable(bool $on, bool $takesTab): void
    {
        $window = $this->host()->native();
        if (! $on && $window->firstResponder() === $this->native) {
            $this->host()->focusTracker()->quietly(static function () use ($window): void {
                $window->makeFirstResponder(null);
            });
        }
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
        $this->showData(CFData::create(null, $rgba8, strlen($rgba8)), $width, $height);
    }

    protected function applyAddress(int $address, int $width, int $height, int $stride, array $damage): void
    {
        $this->showData(CFData::create(null, $address, $stride * $height), $width, $height);
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
        $this->native->setWantsLayer(true);
        $this->native->layer()?->setMasksToBounds(true);
        $this->native->layer()?->setContentsGravity(kCAGravityResize);
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
     * @throws WindowException When Core Foundation makes no data or Core Graphics refuses the image.
     */
    private function showData(?CFData $data, int $width, int $height): void
    {
        self::$space ??= CGColorSpace::createWithName(kCGColorSpaceSRGB);
        $provider = is_null($data) ? null : CGDataProvider::createWithCFData($data);
        $image = is_null($provider) ? null : CGImage::create(
            $width, $height, 8, 32, $width * 4, self::$space,
            kCGImageAlphaNoneSkipLast | kCGBitmapByteOrder32Big, $provider, null, true, kCGRenderingIntentDefault,
        );
        if (is_null($image)) {
            throw new WindowException("Canvas '{$this->path()}' could not show a {$width}x{$height} image.");
        }

        $this->native->setWantsLayer(true);
        $this->native->layer()?->setContents($image);
    }
}
