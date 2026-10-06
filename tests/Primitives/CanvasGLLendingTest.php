<?php

declare(strict_types=1);

use Jovian\Engines\OpenGL\OpenGLDevice;
use Surface\Contracts\Drawing\LentSurface;
use Surface\Contracts\Drawing\RenderingEngine;
use Surface\Contracts\Drawing\SurfaceBorrower;
use Surface\Contracts\Drawing\SurfaceKind;
use Surface\Contracts\Framebuffers\FormatSpec;
use Surface\Contracts\Framebuffers\Framebuffer;
use Surface\Contracts\Windows\WindowException;
use Surface\Drawing\Gpu\GpuRenderingEngine;
use Surface\Framebuffers\Native\NativeDirtyFramebuffer;
use Surface\NutsAndBolts\Color;

/*
 * The canvas lends an NSOpenGLView's context to a GL engine and copies the
 * engine's frames in its drawRect:. These tests draw real frames through
 * venusian-opengl into a window on screen.
 */

/** A borrower that counts what it was asked to present into; its framebuffer is a plain dirty one. */
final class GLBorrower implements SurfaceBorrower
{
    /** @var list<LentSurface> */
    public array $presented = [];

    public Framebuffer $frame;

    public function __construct()
    {
        $this->frame = new NativeDirtyFramebuffer(FormatSpec::rgba8(), 8, 8);
    }

    public function framebuffer(): Framebuffer
    {
        return $this->frame;
    }

    public function lendingHandles(): array
    {
        return [];
    }

    public function presentInto(LentSurface $surface): bool
    {
        $this->presented[] = $surface;

        return true;
    }
}

beforeEach(function (): void {
    if (! extension_loaded('opengl')) {
        $this->markTestSkipped('needs ext-opengl');
    }
    driver()->closeAll();
    pumpFor(0.05);
    takeMail(session());
});

afterEach(function (): void {
    if (! extension_loaded('opengl')) {
        return;
    }
    driver()->closeAll();
    pumpFor(0.05);
    takeMail(session());
});

/** @return array{\Jovian\Toolkits\Appkit\Windows\AppkitWindow, \Jovian\Toolkits\Appkit\Primitives\AppkitCanvas} a shown window with a filling canvas */
function appkitGlCanvas(int $width = 300, int $height = 200): array
{
    $window = driver()->open('main', $width, $height);
    $canvas = $window->column('m')->canvas('view')->fill();
    $window->present();
    pumpUntil(fn (): bool => $canvas->size()[1] > 0, 3.0);

    return [$window, $canvas];
}

it('lends a GL context when ext-opengl is loaded', function (): void {
    [$window, $canvas] = appkitGlCanvas();

    expect(array_slice($canvas->surfaces(), -1))->toBe([SurfaceKind::GL_CONTEXT]);

    $surface = $canvas->lend(SurfaceKind::GL_CONTEXT, new GLBorrower);
    pumpFor(0.1);

    expect($surface->kind)->toBe(SurfaceKind::GL_CONTEXT)
        ->and($surface->handle('context'))->toBe($canvas->glView()?->openGLContext()?->CGLContextObj())
        ->and($canvas->glView())->toBeInstanceOf(ObjCOpenGLView::class)
        ->and($canvas->glView()->superview()?->pointer())->toBe($canvas->native()->pointer())
        ->and($canvas->glView()->wantsBestResolutionOpenGLSurface())->toBeTrue()
        ->and($surface->size())->toBe($canvas->pixelSize())
        ->and($canvas->lent())->toBe($surface);
});

it('lends before the window is shown: an NSOpenGLView has its context from init', function (): void {
    $window = driver()->open('main', 300, 200);
    $canvas = $window->column('m')->canvas('view')->fill();

    $surface = $canvas->lend(SurfaceKind::GL_CONTEXT, new GLBorrower);

    expect($surface->handle('context'))->toBeGreaterThan(0)
        ->and($surface->handle('context'))->toBe($canvas->glView()?->openGLContext()?->CGLContextObj());
});

it('lends nothing a device it cannot host, naming what it can', function (): void {
    [$window, $canvas] = appkitGlCanvas();

    $canvas->lend(SurfaceKind::DMABUF, new GLBorrower);
})->throws(WindowException::class, 'lends no dmabuf surface (it lends: metal-layer, sdl-window, gl-context).');

it('shows opengl frames in the window with no pixel through PHP', function (): void {
    [$window, $canvas] = appkitGlCanvas(320, 240);

    $engine = new GpuRenderingEngine(OpenGLDevice::adopting(), ...$canvas->pixelSize(), output: $canvas);
    for ($i = 0; $i < 60; $i++) {
        $engine->frame(function (RenderingEngine $g) use ($i, $canvas): void {
            [$width, $height] = $canvas->pixelSize();
            $g->clear(Color::rgb(16, 24, 32));
            $g->fillEllipse($width * (0.2 + 0.6 * $i / 59), $height / 2, $height / 6, $height / 6, Color::rgb(255, 128, 0));
        });
        $canvas->present();
        pumpFor(1 / 60);
    }
    pumpUntil(fn (): bool => $engine->framebuffer()->damage() === [], 1.0);

    expect($canvas->lent()?->size())->toBe($canvas->pixelSize())
        ->and($engine->framebuffer()->damage())->toBe([])
        ->and($canvas->renders())->toBeGreaterThan(0)
        ->and($canvas->native()->layerContents())->toBeNull();
    $engine->release();
});

it('repaints the last frame when the toolkit asks', function (): void {
    [$window, $canvas] = appkitGlCanvas();
    $engine = new GpuRenderingEngine(OpenGLDevice::adopting(), ...$canvas->pixelSize(), output: $canvas);
    $engine->frame(fn (RenderingEngine $g) => $g->clear(Color::rgb(0, 128, 255)));
    $canvas->present();
    pumpFor(0.1);
    $renders = $canvas->renders();

    $canvas->glView()->setNeedsDisplay(true);
    pumpUntil(fn (): bool => $canvas->renders() > $renders, 1.0);

    expect($canvas->renders())->toBeGreaterThan($renders);
    $engine->release();
});

it('re-targets the engine when the window is resized', function (): void {
    [$window, $canvas] = appkitGlCanvas();
    $engine = new GpuRenderingEngine(OpenGLDevice::adopting(), ...$canvas->pixelSize(), output: $canvas);
    $engine->frame(fn (RenderingEngine $g) => $g->clear(Color::rgb(0, 0, 0)));
    $canvas->present();
    $before = $engine->framebuffer();

    $window->native()->setContentSize(new NSSize(400.0, 300.0));
    pumpUntil(fn (): bool => $canvas->pixelSize()[0] !== $before->viewportWidth(), 2.0);
    // Each frame re-targets to the size the canvas has then; a window that settles late is caught by the next.
    pumpUntil(function () use ($engine, $canvas): bool {
        $engine->frame(fn (RenderingEngine $g) => $g->clear(Color::rgb(0, 0, 0)));
        $canvas->present();
        pumpFor(0.05);

        return [$engine->width(), $engine->height()] === $canvas->pixelSize();
    }, 2.0);

    expect($engine->framebuffer())->not->toBe($before)
        ->and([$engine->width(), $engine->height()])->toBe($canvas->pixelSize())
        ->and($canvas->lent()?->size())->toBe($canvas->pixelSize());
    $engine->release();
});

it('shows its framebuffer again after a reclaim', function (): void {
    [$window, $canvas] = appkitGlCanvas();
    $own = $canvas->framebuffer('dirty');
    $own->fill(0xFF6600FF);
    $canvas->present();
    $surface = $canvas->lend(SurfaceKind::GL_CONTEXT, new GLBorrower);
    pumpFor(0.1);

    $canvas->reclaim();
    pumpFor(0.1);
    $own->setSegment(10, 10, 20, 20, 0x000000FF);
    $canvas->present();

    expect($surface->released())->toBeTrue()
        ->and($canvas->glView())->toBeNull()
        ->and($canvas->native()->subviews())->toBe([])
        ->and($canvas->native()->layerContents())->toBeInstanceOf(CGImage::class)
        ->and(array_slice($canvas->surfaces(), -1))->toBe([SurfaceKind::GL_CONTEXT]);
});

it('reclaims before it is removed', function (): void {
    [$window, $canvas] = appkitGlCanvas();
    $surface = $canvas->lend(SurfaceKind::GL_CONTEXT, new GLBorrower);

    $canvas->remove();

    expect($surface->released())->toBeTrue()
        ->and($canvas->isRemoved())->toBeTrue();
});
