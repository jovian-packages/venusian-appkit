<?php

declare(strict_types=1);

use Jovian\Toolkits\Appkit\Primitives\AppkitCanvas;
use Jovian\Toolkits\Appkit\Providers\VenusianAppkitServiceProvider;
use Surface\Contracts\Framebuffers\FormatSpec;
use Surface\Windows\WindowsServiceProvider;
use Voyager\Contracts\Core\FrameworkCore;

/*
 * The canvas's native format, without a window: the package binds BGRA8, and the image is described to
 * match the format the canvas hands it.
 */

it('binds BGRA8 as the native canvas format, which Surface takes when config leaves it null', function (): void {
    $bound = [];
    $app = $this->createMock(FrameworkCore::class);
    $app->method('registerSingleton')->willReturnCallback(function (string $name, Closure $make) use (&$bound): void {
        $bound[$name] = $make;
    });
    (new VenusianAppkitServiceProvider($app))->register();
    $native = ($bound['framebuffers.native_canvas_format'] ?? fn () => null)();

    expect($native)->toBe('bgra8')
        ->and(WindowsServiceProvider::canvasFormat(null, $native)->equals(FormatSpec::bgra8()))->toBeTrue()
        ->and(WindowsServiceProvider::canvasFormat('rgba8', $native)->equals(FormatSpec::rgba8()))->toBeTrue();
});

it('describes BGRA8 as skip-first little-endian, and RGBA8 as skip-last big-endian', function (): void {
    expect(AppkitCanvas::bitmapInfo(FormatSpec::bgra8()))->toBe(kCGImageAlphaNoneSkipFirst | kCGBitmapByteOrder32Little)
        ->and(AppkitCanvas::bitmapInfo(FormatSpec::rgba8()))->toBe(kCGImageAlphaNoneSkipLast | kCGBitmapByteOrder32Big);
});
