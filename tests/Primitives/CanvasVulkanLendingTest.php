<?php

declare(strict_types=1);

use Jovian\Engines\Vulkan\VulkanDevice;
use Surface\Contracts\Drawing\RenderingEngine;
use Surface\Contracts\Drawing\SurfaceKind;
use Surface\Drawing\Gpu\GpuRenderingEngine;
use Surface\NutsAndBolts\Color;

/*
 * The vulkan engine presents through the canvas's CAMetalLayer: the device
 * makes a VkSurfaceKHR over the layer (MoltenVK) and swaps into it. No new
 * canvas code: these tests draw real frames through venusian-vulkan into a
 * window on screen.
 */

beforeEach(function (): void {
    if (! class_exists(CAMetalLayer::class) || ! extension_loaded('vulkan')) {
        $this->markTestSkipped('needs ext-metal and ext-vulkan');
    }
    driver()->closeAll();
    pumpFor(0.05);
    takeMail(session());
});

afterEach(function (): void {
    if (! class_exists(CAMetalLayer::class) || ! extension_loaded('vulkan')) {
        return;
    }
    driver()->closeAll();
    pumpFor(0.05);
    takeMail(session());
});

it('shows vulkan frames in the window through the Metal layer', function (): void {
    $window = driver()->open('main', 320, 240);
    $canvas = $window->column('m')->canvas('view')->fill();
    $window->present();
    pumpFor(0.2);

    $engine = new GpuRenderingEngine(new VulkanDevice, ...$canvas->pixelSize(), output: $canvas);
    $shown = 0;
    for ($i = 0; $i < 60; $i++) {
        $engine->frame(function (RenderingEngine $g) use ($i, $canvas): void {
            [$width, $height] = $canvas->pixelSize();
            $g->clear(Color::rgb(16, 24, 32));
            $g->fillEllipse($width * (0.2 + 0.6 * $i / 59), $height / 2, $height / 6, $height / 6, Color::rgb(255, 128, 0));
            $g->text('vulkan', 8, 8, Color::rgb(255, 255, 255), new Surface\Fonts\ClassicFont);
        });
        $canvas->present();
        $shown += $canvas->boundFramebuffer()->damage() === [] ? 1 : 0;
        pumpFor(0.016);
    }

    expect($canvas->lent()?->kind)->toBe(SurfaceKind::METAL_LAYER)
        ->and($canvas->boundFramebuffer())->toBe($engine->framebuffer())
        ->and($shown)->toBeGreaterThan(30)
        ->and($engine->device()->swapchainExtent())->toBe($canvas->pixelSize())
        ->and($canvas->native()->layerContents())->toBeNull();
    $engine->release();
    expect($canvas->lent())->toBeNull();
});

it('re-targets the engine when the window is resized', function (): void {
    $window = driver()->open('main', 300, 200);
    $canvas = $window->column('m')->canvas('view')->fill();
    $window->present();
    pumpFor(0.2);
    $engine = new GpuRenderingEngine(new VulkanDevice, ...$canvas->pixelSize(), output: $canvas);
    $engine->frame(fn (RenderingEngine $g) => $g->clear(Color::rgb(0, 0, 0)));
    $canvas->present();
    $before = $engine->framebuffer();

    $window->native()->setContentSize(new NSSize(400.0, 300.0));
    pumpFor(0.2);
    $engine->frame(fn (RenderingEngine $g) => $g->clear(Color::rgb(0, 0, 0)));
    $canvas->present();
    pumpFor(0.05);
    $engine->frame(fn (RenderingEngine $g) => $g->clear(Color::rgb(0, 0, 32)));
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
    $engine = new GpuRenderingEngine(new VulkanDevice, ...$canvas->pixelSize(), output: $canvas);
    $engine->frame(fn (RenderingEngine $g) => $g->clear(Color::rgb(0, 0, 255)));
    $canvas->present();
    pumpFor(0.1);
    $surface = $canvas->lent();

    $engine->release();
    $own->setSegment(10, 10, 20, 20, 0x000000FF);
    $canvas->present();

    expect($surface?->released())->toBeTrue()
        ->and($canvas->native()->layer()?->pointer())->not->toBe($surface?->handle('layer'))
        ->and($canvas->native()->layerContents())->toBeInstanceOf(CGImage::class)->not->toBe($first);
});
