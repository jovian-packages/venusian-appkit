<?php

namespace Jovian\Toolkits\Appkit\Windows\Concerns;

use CALayer;
use CAMetalLayer;
use CGSize;
use NSLayoutConstraint;
use NSOpenGLPixelFormat;
use NSRect;
use NSView;
use NSWindow;
use ObjCOpenGLView;
use SDL_Window;
use Surface\Contracts\Drawing\LentSurface;
use Surface\Contracts\Drawing\SurfaceBorrower;
use Surface\Contracts\Drawing\SurfaceKind;
use Surface\Contracts\Framebuffers\DamageTrackingFramebuffer;
use Surface\Contracts\Windows\WindowException;

/**
 * Lending for an AppKit host (a canvas or a staged window): the view it draws
 * in lends its layer as a CAMetalLayer, an NSOpenGLView pinned over it, or an
 * SDL window wrapping its NSWindow.
 *
 * Metal: ext-metal's CAMetalLayer is set as the view's layer while lent;
 * reclaimed, the host restores a backing layer of its own (restoreOwnLayer()).
 *
 * GL (ext-opengl loaded): an NSOpenGLView (OpenGL 4.1 core, double buffered,
 * at the backing resolution) pinned over the view. present() marks it for
 * display, and its drawRect: copies the borrower's frame into framebuffer 0
 * and flushes, so a redraw AppKit asks for on its own copies the last frame again.
 *
 * SDL (ext-sdl3 and ext-metal loaded): an SDL window over the host's NSWindow,
 * wrapped as SDL wraps an existing window: its content view stays the content
 * view. SDL_GPU puts its swapchain view in the content view when a device
 * claims the window; presentLent() moves that view into the lending view and
 * pins it to its edges.
 *
 * For a class whose parent uses HostsDrawing: presentLent() ends in the parent's.
 * The host supplies the view, the window and its own layer.
 */
trait LendsAppkitSurfaces
{
    /** SDL_PROP_WINDOW_CREATE_COCOA_WINDOW_POINTER's value. */
    private const string SDL_COCOA_WINDOW = 'SDL.window.create.cocoa.window';

    /** The ext-metal layer, held while lent. */
    protected ?CAMetalLayer $metal_layer = null;

    /** The SDL window wrapping the host's NSWindow, held while lent. */
    protected ?SDL_Window $sdl_window = null;

    /** SDL's swapchain view, moved into the lending view. */
    protected ?NSView $sdl_view = null;

    /** @var list<int> The content view's subviews before SDL claimed the window, by address. */
    protected array $sdl_before = [];

    /** @var list<NSLayoutConstraint> */
    protected array $sdl_pins = [];

    /** @var array<int, array{SDL_Window, NSView}> SDL windows reclaimed while a device still held them, with SDL's swapchain view. */
    protected static array $sdl_parked = [];

    /** The GL view while a GL context is lent; null otherwise. */
    protected ?ObjCOpenGLView $gl_view = null;

    /** @var list<NSLayoutConstraint> The GL view's pins to the lending view's edges. */
    protected array $gl_pins = [];

    /** drawRect: calls the GL view has run, for tests. */
    protected int $renders = 0;

    /** The view surfaces are lent in. */
    abstract protected function lendingView(): NSView;

    /** The NSWindow the lending view is in. */
    abstract protected function lendingWindow(): NSWindow;

    /** Put a backing layer of the view's own back after a Metal layer is reclaimed. */
    abstract protected function restoreOwnLayer(): void;

    /** The GL view over the host while a GL context is lent. */
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
     * A Metal layer when ext-metal is loaded to make one; an SDL window over the host's window
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
        match ($kind) {
            SurfaceKind::GL_CONTEXT => $this->removeGLView(),
            SurfaceKind::SDL_WINDOW => $this->removeSdlWindow(),
            default => $this->removeMetalLayer(),
        };
    }

    /** @return array{layer: int} */
    private function makeMetalLayer(): array
    {
        $layer = CAMetalLayer::layer();
        $layer->setContentsScale($this->nativeScale());
        $this->lendingView()->setLayer(CALayer::fromPointer($layer->pointer()));
        $this->metal_layer = $layer;

        return ['layer' => $layer->pointer()];
    }

    private function removeMetalLayer(): void
    {
        $this->lendingView()->setLayer(null);
        $this->restoreOwnLayer();
        $this->metal_layer = null;
    }

    /**
     * SDL 3.4 makes any view it is handed its window's content view, so the
     * content view itself is handed over. SDL's video subsystem comes up here
     * (reference-counted) and is never quit by the host.
     *
     * @return array{window: int}
     */
    private function makeSdlWindow(): array
    {
        if (! SDL_InitSubSystem(SDL_INIT_VIDEO)) {
            throw new WindowException("{$this->hostName()} could not start SDL video: ".SDL_GetError());
        }
        self::sweepSdlWindows();
        $host = $this->lendingWindow();
        $content = $host->contentView() ?? throw new WindowException("{$this->hostName()} has no content view to wrap.");
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
            throw new WindowException("{$this->hostName()} could not wrap its window in an SDL window: ".SDL_GetError());
        }
        $this->sdl_window = $window;

        return ['window' => $window->pointer()];
    }

    /**
     * An NSOpenGLView pinned over the lending view. It has its context from init, so the
     * host lends before its window is shown.
     *
     * @return array{context: int}
     * @throws WindowException When no OpenGL 4.1 core pixel format is offered.
     */
    private function makeGLView(): array
    {
        $format = NSOpenGLPixelFormat::initWithAttributes([NSOpenGLPFAOpenGLProfile, NSOpenGLProfileVersion4_1Core, NSOpenGLPFAAccelerated, NSOpenGLPFADoubleBuffer, NSOpenGLPFAColorSize, 24, NSOpenGLPFAAlphaSize, 8, 0])
            ?? throw new WindowException("{$this->hostName()} found no OpenGL 4.1 core pixel format.");
        $view = ObjCOpenGLView::initWithFramePixelFormatDraw(new NSRect(), $format, function (ObjCOpenGLView $view): void {
            $this->renders++;
            $this->renderLent();
        });
        $view->setWantsBestResolutionOpenGLSurface(true);
        $context = $view->openGLContext()?->CGLContextObj()
            ?? throw new WindowException("{$this->hostName()}: the NSOpenGLView made no context.");
        $this->gl_pins = $this->pinInside($view);
        $this->gl_view = $view;

        return ['context' => $context];
    }

    /** The GL view out of the host; the view shows its own drawing again. */
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

    /** SDL's swapchain view into the lending view, once a device has claimed the window; its drawable at the host's pixel size. */
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
        foreach ($this->lendingWindow()->contentView()?->subviews() ?? [] as $view) {
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
            $this->sdl_pins = $this->pinInside($view);
            $this->sdl_view = $view;
        }
        // SDL sizes the drawable only when its window's pixel size changes; the host can change size inside an unchanged window.
        [$width, $height] = $this->pixelSize();
        CAMetalLayer::fromPointer($this->sdl_view->layer()->pointer())->setDrawableSize(new CGSize((float) $width, (float) $height));
    }

    /**
     * The SDL window goes once no device holds it. SDL requires a window
     * released from its GPU device before it is destroyed, and the lend is
     * reclaimed before the borrower's device lets go: while SDL's swapchain
     * view is still in place the window is parked, hidden, and destroyed by
     * the first sweep (the session's pump, a present or SDL lend) after SDL
     * takes the view back.
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
     * it). The session calls this every pump; a host, at every present and SDL lend.
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
     * $view added to the lending view and pinned to its four edges.
     *
     * @return list<NSLayoutConstraint>
     */
    private function pinInside(NSView $view): array
    {
        $host = $this->lendingView();
        $host->addSubview($view);
        $view->setTranslatesAutoresizingMaskIntoConstraints(false);
        $pins = [
            $view->leadingAnchor()->constraintEqualToAnchor($host->leadingAnchor()),
            $view->trailingAnchor()->constraintEqualToAnchor($host->trailingAnchor()),
            $view->topAnchor()->constraintEqualToAnchor($host->topAnchor()),
            $view->bottomAnchor()->constraintEqualToAnchor($host->bottomAnchor()),
        ];
        NSLayoutConstraint::activateConstraints($pins);

        return $pins;
    }
}
