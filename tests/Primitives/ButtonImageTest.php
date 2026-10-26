<?php

declare(strict_types=1);

use Surface\Contracts\Framebuffers\FormatSpec;
use Surface\Contracts\Windows\Primitives\ImagePosition;
use Surface\Framebuffers\Native\NativeFullFramebuffer;

beforeEach(function (): void {
    driver()->closeAll();
    pumpFor(0.05);
});

afterEach(function (): void {
    driver()->closeAll();
    pumpFor(0.05);
});

dataset('image buttons', [
    'push' => ['button'],
    'toggle' => ['toggle'],
]);

it('shows a framebuffer image at its size in points, in each position', function (string $kind): void {
    $window = driver()->open('main', 300, 200);
    $column = $window->column('m');
    $button = $kind === 'button' ? $column->button('b', 'Open') : $column->toggleButton('b', 'Open');
    $image = (new NativeFullFramebuffer(FormatSpec::rgba8(), 32, 32))->fill(0xff4020ff);

    $button->setImage($image, ImagePosition::ABOVE, 2.0);
    expect($button->native()->image()->size()->width)->toBe(16.0)
        ->and($button->native()->image()->size()->height)->toBe(16.0)
        ->and($button->native()->imagePosition())->toBe(NSCellImagePosition::IMAGE_ABOVE);

    $button->setImage($image, ImagePosition::ONLY);
    expect($button->native()->imagePosition())->toBe(NSCellImagePosition::IMAGE_ONLY)
        ->and($button->native()->image()->size()->width)->toBe(32.0);

    $button->setImage($image);
    expect($button->native()->imagePosition())->toBe(NSCellImagePosition::IMAGE_LEADING);

    $button->setImage(null);
    expect($button->native()->image())->toBeNull();
    $window->close();
})->with('image buttons');

it('shows a borderless toggle\'s bezel while it is on, as Qt and GTK show a flat toggle\'s pressed state', function (): void {
    $window = driver()->open('main', 300, 200);
    $toggle = $window->column('m')->toggleButton('b', 'Sync');
    $toggle->setBordered(false);
    expect($toggle->native()->isBordered())->toBeFalse();

    $toggle->setPressed(true);
    expect($toggle->native()->isBordered())->toBeTrue();
    $toggle->setPressed(false);
    expect($toggle->native()->isBordered())->toBeFalse();

    // The user's click: AppKit flips the state, then sends the action.
    $toggle->native()->performClick(null);
    expect($toggle->native()->isBordered())->toBeTrue()
        ->and($toggle->isBordered())->toBeFalse();
    $toggle->native()->performClick(null);
    expect($toggle->native()->isBordered())->toBeFalse();

    $toggle->setBordered(true);
    expect($toggle->native()->isBordered())->toBeTrue();
    $window->close();
});

it('draws without a bezel when not bordered', function (string $kind): void {
    $window = driver()->open('main', 300, 200);
    $column = $window->column('m');
    $button = $kind === 'button' ? $column->button('b', 'Open') : $column->toggleButton('b', 'Open');

    $button->setBordered(false);
    expect($button->native()->isBordered())->toBeFalse();

    $button->setBordered(true);
    expect($button->native()->isBordered())->toBeTrue();
    $window->close();
})->with('image buttons');
