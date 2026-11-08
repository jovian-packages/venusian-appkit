<?php

declare(strict_types=1);

use Jovian\Toolkits\Appkit\Services\AppkitSystemAccent;
use Surface\NutsAndBolts\Color;

it('is the driver\'s system accent', function (): void {
    expect(driver()->systemAccent())->toBeInstanceOf(AppkitSystemAccent::class)
        ->and(driver()->systemAccent())->toBe(driver()->systemAccent());
});

it('gives the accent of System Settings as an opaque sRGB colour', function (): void {
    $accent = driver()->systemAccent()->color();
    $srgb = NSColor::controlAccentColor()->colorUsingColorSpace(NSColorSpace::sRGBColorSpace());

    expect($accent)->toBeInstanceOf(Color::class)
        ->and($accent->alpha)->toBe(1.0)
        ->and([$accent->red, $accent->green, $accent->blue])
        ->toBe([$srgb->redComponent(), $srgb->greenComponent(), $srgb->blueComponent()]);
});
