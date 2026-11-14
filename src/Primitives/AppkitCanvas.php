<?php

namespace Jovian\Toolkits\Appkit\Primitives;

use CADisplayLink;
use CFData;
use CGColorSpace;
use CGDataProvider;
use CGImage;
use Closure;
use Jovian\Toolkits\Appkit\Primitives\Concerns\AppkitPrimitive;
use NSRect;
use NSRunLoop;
use NSView;
use ObjCTarget;
use Surface\Contracts\Graphics\PacedStage;
use Surface\Contracts\Toolkits\Primitives\Placement;
use Surface\Contracts\Toolkits\ToolkitRuntimeException;
use Surface\Contracts\Toolkits\ToolkitWindow;
use Surface\Toolkits\Primitives\TKCanvas;
use Surface\Toolkits\Primitives\TKPrimitiveGroup;

/**
 * A layer-backed view whose layer contents are the pixels it was last given: each display() wraps
 * the bytes in a CGImage (sRGB, the alpha byte skipped, so opaque) and sets it as the contents,
 * stretched over the bounds. The view has no natural size: the layout sizes it.
 *
 * It takes no keys or pointer events. It lends its view to an engine that draws on macOS itself.
 *
 * It is a paced stage: a display link on the view fires once a refresh of the display the view is
 * on, and what was asked to be shown is shown then. With nothing asked the link is paused, so an
 * idle canvas wakes nothing.
 */
class AppkitCanvas extends TKCanvas implements PacedStage
{
    use AppkitPrimitive;

    protected static ?CGColorSpace $space = null;

    /** What to show at the next refresh; null: nothing asked. */
    protected ?Closure $due = null;

    /** Fires once a refresh while something is due: made the first time something is. */
    protected ?CADisplayLink $link = null;

    /** The display link's target: it must outlive the link. */
    protected ?ObjCTarget $ticker = null;

    public function __construct(string $name, ToolkitWindow $window, ?TKPrimitiveGroup $parent, Placement $placement)
    {
        parent::__construct($name, $window, $parent, $placement);
        $view = $this->adoptNative(NSView::initWithFrame(new NSRect(0.0, 0.0, 0.0, 0.0)));
        $view->setWantsLayer(true);
        $view->layer()?->setMasksToBounds(true);
        $view->layer()?->setContentsGravity(kCAGravityResize);
    }

    public function native(): NSView
    {
        return $this->native;
    }

    /** Its view: an engine that draws on macOS itself puts what it draws with over it. */
    public function nativeSurface(): ?object
    {
        return $this->native;
    }

    public function whenDue(Closure $show): void
    {
        $this->live();
        $this->due = $show;

        if (is_null($this->link)) {
            $this->ticker = new ObjCTarget(fn () => $this->refreshed());
            $this->link = $this->native->displayLinkWithTargetSelector($this->ticker, 'action:');
            // common modes: frames go on showing while a menu is open or the window is being resized
            $this->link->addToRunLoopForMode(NSRunLoop::mainRunLoop(), NSRunLoopCommonModes);
        }
        $this->link->setPaused(false);
    }

    /** The display is due a frame: show what was asked, or with nothing asked stop being woken. */
    protected function refreshed(): void
    {
        if (is_null($this->due)) {
            $this->link?->setPaused(true);

            return;
        }

        [$show, $this->due] = [$this->due, null];
        $show();
    }

    public function remove(): void
    {
        $this->due = null;
        $this->link?->invalidate();
        [$this->link, $this->ticker] = [null, null];

        parent::remove();
    }

    /** The window's backing scale: 2.0 on a Retina display. */
    protected function nativeScale(): float
    {
        return $this->host()->native()->backingScaleFactor();
    }

    /** @throws ToolkitRuntimeException When Core Foundation makes no data or Core Graphics refuses the image. */
    protected function applyPixels(string $rgba8, int $width, int $height): void
    {
        self::$space ??= CGColorSpace::createWithName(kCGColorSpaceSRGB);
        $data = CFData::create(null, $rgba8, strlen($rgba8));
        $provider = is_null($data) ? null : CGDataProvider::createWithCFData($data);
        // R G B X read as a big-endian word: the bytes in the order they were given
        $image = is_null($provider) ? null : CGImage::create(
            $width, $height, 8, 32, $width * 4, self::$space,
            kCGImageAlphaNoneSkipLast | kCGBitmapByteOrder32Big, $provider, null, true, kCGRenderingIntentDefault,
        );
        if (is_null($image)) {
            throw new ToolkitRuntimeException("Canvas '{$this->path()}' could not show a {$width}x{$height} image.");
        }
        $this->native->layer()?->setContents($image);
    }
}
