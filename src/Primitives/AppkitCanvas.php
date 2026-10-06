<?php

namespace Jovian\Toolkits\Appkit\Primitives;

use CALayer;
use CAMetalLayer;
use CFData;
use CGColorSpace;
use CGDataProvider;
use CGImage;
use Jovian\Toolkits\Appkit\Primitives\Concerns\AppkitPrimitive;
use CGSize;
use NSLayoutConstraint;
use NSOpenGLPixelFormat;
use NSRect;
use NSView;
use ObjCOpenGLView;
use SDL_Window;
use Surface\Contracts\Drawing\LentSurface;
use Surface\Contracts\Drawing\SurfaceBorrower;
use Surface\Contracts\Drawing\SurfaceKind;
use Surface\Contracts\Framebuffers\DamageTrackingFramebuffer;
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

    /** SDL_PROP_WINDOW_CREATE_COCOA_WINDOW_POINTER's value: ext-sdl3 0.10.0 defines the view property's constant, not this one. */
    private const string SDL_COCOA_WINDOW = 'SDL.window.create.cocoa.window';

    protected static ?CGColorSpace $space = null;

    /** The ext-metal layer, held while lent. */
    protected ?CAMetalLayer $metal_layer = null;

    /** The SDL window wrapping the view, held while lent. */
    protected ?SDL_Window $sdl_window = null;

    /** SDL's swapchain view, moved into the canvas. */
    protected ?NSView $sdl_view = null;

    /** @var list<int> The content view's subviews before SDL claimed the window, by address. */
    protected array $sdl_before = [];

    /** @var list<NSLayoutConstraint> */
    protected array $sdl_pins = [];

    /** @var array<int, array{SDL_Window, NSView}> SDL windows reclaimed while a device still held them, with SDL's swapchain view. */
    protected static array $sdl_parked = [];

    /** The GL view while a GL context is lent; null otherwise. */
    protected ?ObjCOpenGLView $gl_view = null;

    /** @var list<NSLayoutConstraint> The GL view's pins to the canvas's edges. */
    protected array $gl_pins = [];

    /** drawRect: calls the GL view has run, for tests. */
    protected int $renders = 0;

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
        $this->showData(CFData::create($rgba8), $width, $height);
    }

    protected function applyAddress(int $address, int $width, int $height, int $stride, array $damage): void
    {
        $this->showData(CFData::create($address, $stride * $height), $width, $height);
    }

    /** The GL view over the canvas while a GL context is lent. */
    public function glView(): ?ObjCOpenGLView
    {
        return $this->gl_view;
    }

    /** drawRect: calls the GL view has run. */
    public function renders(): int
    {
        return $this->renders;
    }

    /**
     * A Metal layer when ext-metal is loaded to make one; an SDL window over the canvas's window
     * when ext-sdl3 is too; a GL context when ext-opengl is loaded.
     */
    public function surfaces(): array
    {
        $kinds = [];
        if (class_exists(CAMetalLayer::class)) {
            $kinds[] = SurfaceKind::METAL_LAYER;
        }
        // The swapchain view's drawable is sized through ext-metal's CAMetalLayer.
        if (function_exists('SDL_CreateWindowWithProperties') && class_exists(CAMetalLayer::class)) {
            $kinds[] = SurfaceKind::SDL_WINDOW;
        }
        if (function_exists('CGLGetCurrentContext')) {
            $kinds[] = SurfaceKind::GL_CONTEXT;
        }

        return $kinds;
    }

    protected function makeSurface(SurfaceKind $kind, array $handles): array
    {
        return match ($kind) {
            SurfaceKind::SDL_WINDOW => $this->makeSdlWindow(),
            SurfaceKind::GL_CONTEXT => $this->makeGLView(),
            default => $this->makeMetalLayer(),
        };
    }

    protected function removeSurface(SurfaceKind $kind): void
    {
        if ($kind === SurfaceKind::GL_CONTEXT) {
            $this->removeGLView();

            return;
        }
        if ($kind === SurfaceKind::SDL_WINDOW) {
            $this->removeSdlWindow();

            return;
        }
        $this->removeMetalLayer();
    }

    /** @return array{layer: int} */
    private function makeMetalLayer(): array
    {
        $layer = CAMetalLayer::layer();
        $layer->setContentsScale($this->nativeScale());
        $this->native->setLayer(CALayer::fromPointer($layer->pointer()));
        $this->metal_layer = $layer;

        return ['layer' => $layer->pointer()];
    }

    /** A backing layer of the view's own again, set up as the constructor set the first. */
    private function removeMetalLayer(): void
    {
        $this->native->setLayer(null);
        $this->native->setLayerMasksToBounds(true);
        $this->native->setLayerContentsGravity(kCAGravityResize);
        $this->metal_layer = null;
    }

    /**
     * An SDL window over the canvas's NSWindow, wrapped as SDL wraps an
     * existing window: its content view stays the content view. SDL 3.4 makes
     * any view it is handed its window's content view, so the canvas's own
     * view is never handed over. SDL_GPU puts its swapchain view in the
     * content view when a device claims the window; presentLent() moves that
     * view into the canvas and pins it to the canvas's edges. SDL's video
     * subsystem comes up here (reference-counted) and is never quit by the
     * canvas.
     *
     * @return array{window: int}
     */
    private function makeSdlWindow(): array
    {
        if (! SDL_InitSubSystem(SDL_INIT_VIDEO)) {
            throw new WindowException("Canvas '{$this->path()}' could not start SDL video: ".SDL_GetError());
        }
        self::sweepSdlWindows();
        $host = $this->host()->native();
        $content = $host->contentView() ?? throw new WindowException("Canvas '{$this->path()}' has no content view to wrap.");
        $this->sdl_before = array_map(fn (NSView $view): int => $view->pointer(), $content->subviews());
        // SDL hooks its listener in as the next responder of the window and its content view; the
        // links are put back at once, so events still reach the window, and SDL's close leaves them.
        $chain = [$host->nextResponder(), $content->nextResponder()];
        $props = SDL_CreateProperties();
        SDL_SetPointerProperty($props, self::SDL_COCOA_WINDOW, $host->pointer());
        SDL_SetPointerProperty($props, SDL_PROP_WINDOW_CREATE_COCOA_VIEW_POINTER, $content->pointer());
        SDL_SetBooleanProperty($props, SDL_PROP_WINDOW_CREATE_HIGH_PIXEL_DENSITY_BOOLEAN, true);
        $window = SDL_CreateWindowWithProperties($props);
        SDL_DestroyProperties($props);
        $host->setNextResponder($chain[0]);
        $content->setNextResponder($chain[1]);
        if (is_null($window)) {
            throw new WindowException("Canvas '{$this->path()}' could not wrap its window in an SDL window: ".SDL_GetError());
        }
        $this->sdl_window = $window;

        return ['window' => $window->pointer()];
    }

    /**
     * An NSOpenGLView pinned over the canvas's view. It has its context from init, so the
     * canvas lends before its window is shown.
     *
     * @return array{context: int}
     * @throws WindowException When no OpenGL 4.1 core pixel format is offered.
     */
    private function makeGLView(): array
    {
        $format = NSOpenGLPixelFormat::initWithAttributes([NSOpenGLPFAOpenGLProfile, NSOpenGLProfileVersion4_1Core, NSOpenGLPFAAccelerated, NSOpenGLPFADoubleBuffer, NSOpenGLPFAColorSize, 24, NSOpenGLPFAAlphaSize, 8, 0])
            ?? throw new WindowException("Canvas '{$this->path()}' found no OpenGL 4.1 core pixel format.");
        $view = ObjCOpenGLView::initWithFramePixelFormatDraw(new NSRect(), $format, function (ObjCOpenGLView $view): void {
            $this->renders++;
            $this->renderLent();
        });
        $view->setWantsBestResolutionOpenGLSurface(true);
        $context = $view->openGLContext()?->CGLContextObj()
            ?? throw new WindowException("Canvas '{$this->path()}': the NSOpenGLView made no context.");
        $this->native->addSubview($view);
        $view->setTranslatesAutoresizingMaskIntoConstraints(false);
        $this->gl_pins = [
            $view->leadingAnchor()->constraintEqualToAnchor($this->native->leadingAnchor()),
            $view->trailingAnchor()->constraintEqualToAnchor($this->native->trailingAnchor()),
            $view->topAnchor()->constraintEqualToAnchor($this->native->topAnchor()),
            $view->bottomAnchor()->constraintEqualToAnchor($this->native->bottomAnchor()),
        ];
        NSLayoutConstraint::activateConstraints($this->gl_pins);
        $this->gl_view = $view;

        return ['context' => $context];
    }

    /** The GL view out of the canvas; the view shows its layer contents again. */
    private function removeGLView(): void
    {
        NSLayoutConstraint::deactivateConstraints($this->gl_pins);
        $this->gl_pins = [];
        $this->gl_view?->removeFromSuperview();
        $this->gl_view = null;
    }

    /**
     * The GL view's drawRect:, its context current and framebuffer 0 its own: the borrower
     * copies its frame in, and the frame's epoch begins anew. Called with no borrower
     * (reclaimed, a redraw still queued) the view is cleared to black.
     */
    protected function renderLent(): void
    {
        $surface = $this->lent;
        $borrower = $this->borrower;
        if (is_null($surface) || is_null($borrower) || $surface->released()) {
            \glClearColor(0.0, 0.0, 0.0, 1.0);
            \glClear(GL_COLOR_BUFFER_BIT);

            return;
        }
        $borrower->presentInto($surface);
        $frame = $borrower->framebuffer();
        if ($frame instanceof DamageTrackingFramebuffer) {
            $frame->beginEpoch();
        }
    }

    /** Parked SDL windows whose device has let go are destroyed first. */
    public function present(): static
    {
        self::sweepSdlWindows();

        return parent::present();
    }

    /** SDL's swapchain view into the canvas, once a device has claimed the window; its drawable at the canvas's pixel size. */
    protected function presentLent(LentSurface $surface, SurfaceBorrower $borrower): static
    {
        // GL frames are copied inside drawRect:: presenting marks the view for display.
        if (! is_null($this->gl_view)) {
            $this->gl_view->setNeedsDisplay(true);

            return $this;
        }
        if ($surface->kind === SurfaceKind::SDL_WINDOW) {
            $this->holdSdlView();
        }

        return parent::presentLent($surface, $borrower);
    }

    /**
     * The view SDL_GPU added to the content view when a device claimed the
     * window: the one subview that was not there before the lend.
     */
    private function claimedSdlView(): ?NSView
    {
        foreach ($this->host()->native()->contentView()?->subviews() ?? [] as $view) {
            if (! in_array($view->pointer(), $this->sdl_before, true)) {
                return $view;
            }
        }

        return null;
    }

    private function holdSdlView(): void
    {
        if (is_null($this->sdl_view)) {
            $view = $this->claimedSdlView();
            if (is_null($view)) {
                return;
            }
            $view->removeFromSuperview();
            $this->native->addSubview($view);
            $view->setTranslatesAutoresizingMaskIntoConstraints(false);
            $this->sdl_pins = [
                $view->leadingAnchor()->constraintEqualToAnchor($this->native->leadingAnchor()),
                $view->trailingAnchor()->constraintEqualToAnchor($this->native->trailingAnchor()),
                $view->topAnchor()->constraintEqualToAnchor($this->native->topAnchor()),
                $view->bottomAnchor()->constraintEqualToAnchor($this->native->bottomAnchor()),
            ];
            NSLayoutConstraint::activateConstraints($this->sdl_pins);
            $this->sdl_view = $view;
        }
        // SDL sizes the drawable only when its window's pixel size changes; the canvas can change size inside an unchanged window.
        [$width, $height] = $this->pixelSize();
        CAMetalLayer::fromPointer($this->sdl_view->layer()->pointer())->setDrawableSize(new CGSize((float) $width, (float) $height));
    }

    /**
     * The SDL window goes once no device holds it. SDL requires a window
     * released from its GPU device before it is destroyed, and the lend is
     * reclaimed before the borrower's device lets go: while SDL's swapchain
     * view is still in place the window is parked, hidden, and destroyed by
     * the first sweep (the session's pump, a canvas present or SDL lend)
     * after SDL takes the view back.
     */
    private function removeSdlWindow(): void
    {
        NSLayoutConstraint::deactivateConstraints($this->sdl_pins);
        $this->sdl_pins = [];
        $view = $this->sdl_view ?? $this->claimedSdlView();
        if (! is_null($this->sdl_window)) {
            if (! is_null($view) && ! is_null($view->superview())) {
                $view->setHidden(true);
                self::$sdl_parked[] = [$this->sdl_window, $view];
            } else {
                SDL_DestroyWindow($this->sdl_window);
            }
        }
        $this->sdl_window = null;
        $this->sdl_view = null;
        $this->sdl_before = [];
    }

    /**
     * Destroy every parked SDL window whose swapchain view SDL has removed (its device let go of
     * it). The session calls this every pump; a canvas, at every present and SDL lend.
     */
    public static function sweepSdlWindows(): void
    {
        foreach (self::$sdl_parked as $at => [$window, $view]) {
            if (is_null($view->superview())) {
                SDL_DestroyWindow($window);
                unset(self::$sdl_parked[$at]);
            }
        }
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
