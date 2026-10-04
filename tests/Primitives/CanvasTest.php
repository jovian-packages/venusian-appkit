<?php

declare(strict_types=1);

use Surface\Contracts\Framebuffers\DamageTrackingFramebuffer;
use Surface\Contracts\Windows\WindowException;

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

it('lays a canvas out like any view and measures it in device pixels', function (): void {
    $window = driver()->open('main', 300, 200);
    $main = $window->column('m');
    $main->label('title', 'Canvas');
    $canvas = $main->canvas('view')->fill();
    $window->present();
    pumpFor(0.2);

    [$width, $height] = $canvas->size();
    $scale = $window->native()->backingScaleFactor();

    expect($width)->toBe(300)
        ->and($height)->toBeGreaterThan(100)->toBeLessThan(200)
        ->and($canvas->pixelSize())->toBe([(int) round($width * $scale), (int) round($height * $scale)])
        ->and($canvas->native()->layerContents())->toBeNull()
        ->and($canvas->native()->layerContentsGravity())->toBe(kCAGravityResize)
        ->and($canvas->native()->layerMasksToBounds())->toBeTrue();
});

it('shows its framebuffer as the view\'s layer contents, only when something was drawn', function (): void {
    $window = driver()->open('main', 300, 200);
    $canvas = $window->column('m')->canvas('view')->fill();
    $window->present();
    pumpFor(0.2);

    $buffer = $canvas->framebuffer('dirty');
    $buffer->fill(0xFF6600FF);
    $canvas->present();
    pumpFor(0.05);

    $shown = $canvas->native()->layerContents();
    expect($buffer)->toBeInstanceOf(DamageTrackingFramebuffer::class)
        ->and($shown)->toBeInstanceOf(CGImage::class)
        ->and([$shown->getWidth(), $shown->getHeight()])->toBe($canvas->pixelSize());

    $canvas->present();                                             // nothing drawn since: the same image stays up
    expect($canvas->native()->layerContents())->toBe($shown);

    $buffer->setSegment(10, 10, 20, 20, 0x000000FF);
    $canvas->present();
    expect($canvas->native()->layerContents())->toBeInstanceOf(CGImage::class)->not->toBe($shown);
});

it('stretches a framebuffer of another size over the view', function (): void {
    $window = driver()->open('main', 300, 200);
    $canvas = $window->column('m')->canvas('view')->fill();
    $window->present();
    pumpFor(0.2);

    $canvas->framebuffer('full', 32, 24)->fill(0x3366CCFF);
    $canvas->present();

    $shown = $canvas->native()->layerContents();
    expect([$shown->getWidth(), $shown->getHeight()])->toBe([32, 24])
        ->and($canvas->size()[0])->toBe(300);
});

it('takes a framebuffer before the window is shown when given a size, and goes with its view', function (): void {
    $window = driver()->open('main', 300, 200);
    $canvas = $window->column('m')->canvas('view')->fill();
    $canvas->framebuffer('ring', 16, 8)->fill(0xFFFFFFFF);
    $canvas->boundFramebuffer()->present();
    $canvas->present();

    expect($canvas->native()->layerContents()->getWidth())->toBe(16);

    $canvas->remove();
    expect(fn () => $canvas->present())->toThrow(WindowException::class, 'was removed')
        ->and($window->view('m.view'))->toBeNull();
});
