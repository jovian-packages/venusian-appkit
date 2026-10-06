<?php

declare(strict_types=1);

use Jovian\Engines\Metal\MetalDevice;
use Surface\Contracts\Drawing\RenderingEngine;
use Surface\Contracts\Drawing\SurfaceKind;
use Surface\Contracts\Windows\WindowException;
use Surface\Drawing\Gpu\GpuRenderingEngine;
use Surface\NutsAndBolts\Color;

/*
 * The canvas lends its view's layer to a GPU engine: a CAMetalLayer from
 * ext-metal, set as the layer of the view. These tests draw real frames
 * through venusian-metal into a window on screen.
 */

beforeEach(function (): void {
    driver()->closeAll();
    pumpFor(0.05);
    takeMail(session());
});

afterEach(function (): void {
    driver()->closeAll();
    pumpFor(0.05);
    takeMail(session());
});

it('lends a Metal layer when ext-metal is loaded', function (): void {
    $window = driver()->open('main', 300, 200);
    $canvas = $window->column('m')->canvas('view')->fill();
    $window->present();
    pumpFor(0.2);

    // The Metal layer first; ext-sdl3 adds the SDL window after it.
    expect($canvas->surfaces()[0])->toBe(SurfaceKind::METAL_LAYER);

    $surface = $canvas->lend(SurfaceKind::METAL_LAYER, new LayerBorrower);
    $layer = CAMetalLayer::fromPointer($surface->handle('layer'));

    expect($surface->kind)->toBe(SurfaceKind::METAL_LAYER)
        ->and($canvas->native()->layer()?->pointer())->toBe($surface->handle('layer'))
        ->and($layer->contentsScale())->toBe($window->native()->backingScaleFactor())
        ->and($surface->size())->toBe($canvas->pixelSize())
        ->and($canvas->lent())->toBe($surface);
});

it('lends nothing a device it cannot host, naming what it can', function (): void {
    $window = driver()->open('main', 300, 200);
    $canvas = $window->column('m')->canvas('view')->fill();
    $window->present();
    pumpFor(0.2);

    $canvas->lend(SurfaceKind::DMABUF, new LayerBorrower);
})->throws(WindowException::class, 'lends no dmabuf surface (it lends: metal-layer, sdl-window, gl-context).');

it('shows metal frames in the window with no pixel through PHP', function (): void {
    $window = driver()->open('main', 320, 240);
    $canvas = $window->column('m')->canvas('view')->fill();
    $window->present();
    pumpFor(0.2);

    $engine = new GpuRenderingEngine(new MetalDevice, ...$canvas->pixelSize(), output: $canvas);
    $shown = 0;
    for ($i = 0; $i < 60; $i++) {
        $engine->frame(function (RenderingEngine $g) use ($i, $canvas): void {
            [$width, $height] = $canvas->pixelSize();
            $g->clear(Color::rgb(16, 24, 32));
            $g->fillEllipse($width * (0.2 + 0.6 * $i / 59), $height / 2, $height / 6, $height / 6, Color::rgb(255, 128, 0));
            $g->text('metal', 8, 8, Color::rgb(255, 255, 255), new Surface\Fonts\ClassicFont);
        });
        $canvas->present();
        $shown += $canvas->boundFramebuffer()->damage() === [] ? 1 : 0;
        pumpFor(0.016);
    }

    expect($canvas->boundFramebuffer())->toBe($engine->framebuffer())
        ->and($shown)->toBeGreaterThan(30)
        ->and($canvas->native()->layerContents())->toBeNull();
    $engine->release();
    expect($canvas->lent())->toBeNull();
});

it('re-targets the engine when the window is resized', function (): void {
    $window = driver()->open('main', 300, 200);
    $canvas = $window->column('m')->canvas('view')->fill();
    $window->present();
    pumpFor(0.2);
    $engine = new GpuRenderingEngine(new MetalDevice, ...$canvas->pixelSize(), output: $canvas);
    $engine->frame(fn (RenderingEngine $g) => $g->clear(Color::rgb(0, 0, 0)));
    $before = $engine->framebuffer();

    $window->native()->setContentSize(new NSSize(400.0, 300.0));
    pumpFor(0.2);
    $engine->frame(fn (RenderingEngine $g) => $g->clear(Color::rgb(0, 0, 0)));
    $canvas->present();

    expect($engine->framebuffer())->not->toBe($before)
        ->and([$engine->width(), $engine->height()])->toBe($canvas->pixelSize())
        ->and($canvas->lent()?->size())->toBe($canvas->pixelSize());
    $engine->release();
});

it('shows its framebuffer again after a reclaim', function (): void {
    $window = driver()->open('main', 300, 200);
    $canvas = $window->column('m')->canvas('view')->fill();
    $window->present();
    pumpFor(0.2);
    $own = $canvas->framebuffer('dirty');
    $own->fill(0xFF6600FF);
    $canvas->present();
    $first = $canvas->native()->layerContents();
    $surface = $canvas->lend(SurfaceKind::METAL_LAYER, new LayerBorrower);

    $canvas->reclaim();
    $own->setSegment(10, 10, 20, 20, 0x000000FF);
    $canvas->present();

    expect($surface->released())->toBeTrue()
        ->and($canvas->native()->layer())->not->toBeNull()
        ->and($canvas->native()->layer()?->pointer())->not->toBe($surface->handle('layer'))
        ->and($canvas->native()->layerContents())->toBeInstanceOf(CGImage::class)->not->toBe($first)
        ->and($canvas->native()->layerContentsGravity())->toBe(kCAGravityResize)
        ->and($canvas->native()->layerMasksToBounds())->toBeTrue();
});

it('reclaims before it is removed', function (): void {
    $window = driver()->open('main', 300, 200);
    $canvas = $window->column('m')->canvas('view')->fill();
    $window->present();
    pumpFor(0.2);
    $surface = $canvas->lend(SurfaceKind::METAL_LAYER, new LayerBorrower);

    $canvas->remove();

    expect($surface->released())->toBeTrue()
        ->and($canvas->isRemoved())->toBeTrue();
});
