<?php

declare(strict_types=1);

use Jovian\Engines\Sdl3\Sdl3Device;
use Surface\Contracts\Drawing\RenderingEngine;
use Surface\Contracts\Drawing\SurfaceKind;
use Surface\Contracts\Windows\WindowException;
use Surface\Drawing\Gpu\GpuRenderingEngine;
use Surface\NutsAndBolts\Color;

/*
 * The canvas lends an SDL window over its NSWindow to the sdl3 engine; SDL_GPU's
 * swapchain view is moved into the canvas.
 * LayerBorrower is tests/Pest.php's. These tests draw real frames through
 * venusian-sdl3 into a window on screen.
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

/** A device built by hand needs SDL video up first, as renderer('sdl3') brings it up. */
function sdl3Device(): Sdl3Device
{
    SDL_InitSubSystem(SDL_INIT_VIDEO) || throw new RuntimeException('SDL video: '.SDL_GetError());

    return new Sdl3Device;
}

/** A canvas in a shown window, laid out. */
function sdlCanvas(int $width = 320, int $height = 240): array
{
    $window = driver()->open('main', $width, $height);
    $canvas = $window->column('m')->canvas('view')->fill();
    $window->present();
    pumpFor(0.2);

    return [$window, $canvas];
}

it('lends an SDL window when ext-sdl3 is loaded, after the Metal layer', function (): void {
    [$window, $canvas] = sdlCanvas();

    // ext-opengl adds the GL context after both.
    expect($canvas->surfaces())->toBe([SurfaceKind::METAL_LAYER, SurfaceKind::SDL_WINDOW, ...(extension_loaded('opengl') ? [SurfaceKind::GL_CONTEXT] : [])]);

    $content = $window->native()->contentView()->pointer();

    $surface = $canvas->lend(SurfaceKind::SDL_WINDOW, new LayerBorrower);
    $sdl = SDL_Window::fromPointer($surface->handle('window'));

    // SDL wraps the canvas's NSWindow and leaves its content view, and so the layout, in place.
    expect($surface->kind)->toBe(SurfaceKind::SDL_WINDOW)
        ->and(SDL_GetPointerProperty(SDL_GetWindowProperties($sdl), 'SDL.window.cocoa.window', null))->toBe($window->native()->pointer())
        ->and($window->native()->contentView()->pointer())->toBe($content)
        ->and($canvas->native()->superview())->not->toBeNull()
        ->and($surface->size())->toBe($canvas->pixelSize())
        ->and($canvas->lent())->toBe($surface);
});

it('leaves the application\'s menu bar, delegate and session alone', function (): void {
    [$window, $canvas] = sdlCanvas();
    $app = NSApplication::sharedApplication();
    $menu = $app->mainMenu();
    $delegate = $app->delegate();

    $canvas->lend(SurfaceKind::SDL_WINDOW, new LayerBorrower);
    pumpFor(0.1);

    // SDL makes itself the application's delegate (and its URL handler) whenever the slot is empty.
    expect($app->mainMenu())->toBe($menu)
        ->and($menu)->not->toBeNull()
        ->and($app->delegate())->toBe($delegate)
        ->and($app->delegate()?->className())->not->toBeIn([null, 'SDL3AppDelegate'])
        ->and($app->className())->toBe('NSApplication')
        ->and(session()->connected())->toBeTrue();
});

it('keeps the window\'s responder chain through a lend and a reclaim', function (): void {
    // SDL hooks its listener in as the next responder of the window and its content view.
    [$window, $canvas] = sdlCanvas();
    $host = $window->native();
    $content = $host->contentView();
    $chain = fn (): array => [$host->nextResponder()?->pointer(), $content->nextResponder()?->pointer()];
    $before = $chain();
    $engine = new GpuRenderingEngine(sdl3Device(), ...$canvas->pixelSize(), output: $canvas);
    $engine->frame(fn (RenderingEngine $g) => $g->clear(Color::rgb(0, 0, 0)));
    $canvas->present();

    expect($chain())->toBe($before);

    $engine->release();
    pumpFor(0.05);
    expect($chain())->toBe($before);
});

it('sizes the drawable to the canvas, and again when the canvas changes size in an unchanged window', function (): void {
    $window = driver()->open('main', 320, 240);
    $column = $window->column('m');
    $label = $column->label('top', 'above');
    $canvas = $column->canvas('view')->fill();
    $window->present();
    pumpFor(0.2);
    $engine = new GpuRenderingEngine(sdl3Device(), ...$canvas->pixelSize(), output: $canvas);
    $engine->frame(fn (RenderingEngine $g) => $g->clear(Color::rgb(0, 0, 0)));
    $canvas->present();
    $before = $canvas->pixelSize();
    $drawable = CAMetalLayer::fromPointer($canvas->native()->subviews()[0]->layer()->pointer())->drawableSize();

    // SDL sized its view's drawable to the content view it made it in; the canvas is smaller.
    expect([(int) $drawable->width, (int) $drawable->height])->toBe($before);

    $label->remove();
    pumpFor(0.2);
    $engine->frame(fn (RenderingEngine $g) => $g->clear(Color::rgb(0, 0, 0)));
    $canvas->present();

    $drawable = CAMetalLayer::fromPointer($canvas->native()->subviews()[0]->layer()->pointer())->drawableSize();
    expect($canvas->pixelSize())->not->toBe($before)
        ->and([(int) $drawable->width, (int) $drawable->height])->toBe($canvas->pixelSize());
    $engine->release();
});

it('destroys a parked SDL window on the session\'s next pump', function (): void {
    [$window, $canvas] = sdlCanvas();
    $device = sdl3Device();
    $engine = new GpuRenderingEngine($device, ...$canvas->pixelSize(), output: $canvas);
    $engine->frame(fn (RenderingEngine $g) => $g->clear(Color::rgb(0, 0, 255)));
    $canvas->present();
    $id = SDL_GetWindowID(SDL_Window::fromPointer($canvas->lent()->handle('window')));

    $engine->release();
    pumpFor(0.05);

    expect(SDL_GetWindowFromID($id))->toBeNull();
});

it('lends one surface at a time, of either kind', function (): void {
    [$window, $canvas] = sdlCanvas();
    $canvas->lend(SurfaceKind::METAL_LAYER, new LayerBorrower);

    expect(fn () => $canvas->lend(SurfaceKind::SDL_WINDOW, new LayerBorrower))
        ->toThrow(WindowException::class, 'has already lent its metal-layer surface');

    $canvas->reclaim();
    $surface = $canvas->lend(SurfaceKind::SDL_WINDOW, new LayerBorrower);
    expect($surface->kind)->toBe(SurfaceKind::SDL_WINDOW);
});

it('shows sdl3 frames in the window with no pixel through PHP', function (): void {
    [$window, $canvas] = sdlCanvas();

    $engine = new GpuRenderingEngine(sdl3Device(), ...$canvas->pixelSize(), output: $canvas);
    $shown = 0;
    for ($i = 0; $i < 60; $i++) {
        $engine->frame(function (RenderingEngine $g) use ($i, $canvas): void {
            [$width, $height] = $canvas->pixelSize();
            $g->clear(Color::rgb(32, 16, 24));
            $g->fillEllipse($width * (0.2 + 0.6 * $i / 59), $height / 2, $height / 6, $height / 6, Color::rgb(0, 200, 255));
            $g->text('sdl3', 8, 8, Color::rgb(255, 255, 255), new Surface\Fonts\ClassicFont);
        });
        $canvas->present();
        $shown += $canvas->boundFramebuffer()->damage() === [] ? 1 : 0;
        pumpFor(0.016);
    }

    // SDL's swapchain view sits in the canvas, its drawable at the canvas's pixels.
    $views = $canvas->native()->subviews();
    $drawable = CAMetalLayer::fromPointer($views[0]->layer()->pointer())->drawableSize();
    expect($canvas->boundFramebuffer())->toBe($engine->framebuffer())
        ->and($shown)->toBeGreaterThan(30)
        ->and($views)->toHaveCount(1)
        ->and([(int) $drawable->width, (int) $drawable->height])->toBe($canvas->pixelSize())
        ->and($canvas->native()->layerContents())->toBeNull();
    $engine->release();
    expect($canvas->lent())->toBeNull();
});

it('re-targets the engine when the window is resized', function (): void {
    [$window, $canvas] = sdlCanvas(300, 200);
    $engine = new GpuRenderingEngine(sdl3Device(), ...$canvas->pixelSize(), output: $canvas);
    $engine->frame(fn (RenderingEngine $g) => $g->clear(Color::rgb(0, 0, 0)));
    $before = $engine->framebuffer();

    $window->native()->setContentSize(new NSSize(400.0, 300.0));
    pumpFor(0.2);
    $engine->frame(fn (RenderingEngine $g) => $g->clear(Color::rgb(0, 0, 0)));
    $canvas->present();
    pumpFor(0.05);

    $drawable = CAMetalLayer::fromPointer($canvas->native()->subviews()[0]->layer()->pointer())->drawableSize();
    expect($engine->framebuffer())->not->toBe($before)
        ->and([$engine->width(), $engine->height()])->toBe($canvas->pixelSize())
        ->and([(int) $drawable->width, (int) $drawable->height])->toBe($canvas->pixelSize());
    $engine->release();
});

it('shows its framebuffer again after a reclaim', function (): void {
    [$window, $canvas] = sdlCanvas(300, 200);
    $own = $canvas->framebuffer('dirty');
    $own->fill(0xFF6600FF);
    $canvas->present();
    $first = $canvas->native()->layerContents();
    $engine = new GpuRenderingEngine(sdl3Device(), ...$canvas->pixelSize(), output: $canvas);
    $engine->frame(fn (RenderingEngine $g) => $g->clear(Color::rgb(0, 0, 255)));
    $canvas->present();
    pumpFor(0.05);

    $engine->release();
    $own->setSegment(10, 10, 20, 20, 0x000000FF);
    $canvas->present();
    pumpFor(0.05);

    expect($canvas->lent())->toBeNull()
        ->and($canvas->native()->layerContents())->toBeInstanceOf(CGImage::class)->not->toBe($first)
        ->and($canvas->native()->subviews())->toBe([]);
});

it('reclaims before it is removed', function (): void {
    [$window, $canvas] = sdlCanvas();
    $surface = $canvas->lend(SurfaceKind::SDL_WINDOW, new LayerBorrower);

    $canvas->remove();

    expect($surface->released())->toBeTrue()
        ->and($canvas->isRemoved())->toBeTrue();
});

it('destroys its SDL window only once the device has let go of it', function (string $order): void {
    // SDL requires a window released from its GPU device before it is destroyed.
    [$window, $canvas] = sdlCanvas();
    $device = sdl3Device();
    $engine = new GpuRenderingEngine($device, ...$canvas->pixelSize(), output: $canvas);
    $engine->frame(fn (RenderingEngine $g) => $g->clear(Color::rgb(0, 0, 255)));
    $canvas->present();
    $id = SDL_GetWindowID(SDL_Window::fromPointer($canvas->lent()->handle('window')));

    if ($order === 'reclaim first') {
        $canvas->reclaim();
        expect(SDL_GetWindowFromID($id))->not->toBeNull();
        $device->release();
    } else {
        $device->release();
        $canvas->reclaim();
    }
    $canvas->framebuffer('dirty')->fill(0x000000FF);
    $canvas->present();

    expect(SDL_GetWindowFromID($id))->toBeNull()
        ->and($canvas->native()->subviews())->toBe([]);
})->with(['reclaim first', 'device first']);
