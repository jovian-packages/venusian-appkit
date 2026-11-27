<?php

declare(strict_types=1);

use Surface\Contracts\Toolkits\UI\Align;
use Surface\Contracts\Toolkits\UI\ButtonStyle;
use Surface\Contracts\Toolkits\Styling\FontSpec;

/** A 72 x 72 pixel file at 72 dpi, and a 72 x 36 one: AppKit alone would draw them at 72 points. */
function iconFile(int $width, int $height): string
{
    $path = sys_get_temp_dir()."/appkit-icon-{$width}x{$height}.png";
    $image = imagecreatetruecolor($width, $height);
    imagepng($image, $path);

    return $path;
}

function iconButton(string $file): array
{
    $window = openWindow('icon', 400, 300);
    $button = $window->column('root')->button('go', '')
        ->setStyle(ButtonStyle::BORDERLESS)
        ->setIcon($file)
        ->align(Align::START, Align::START);
    pumpFor(0.1);

    return [$window, $button];
}

it('draws an icon file at the size an SF Symbol has, not at its own', function (): void {
    [$window, $button] = iconButton(iconFile(72, 72));
    $size = $button->native()->image()->size();

    expect([$size->width, $size->height])->toBe([16.0, 16.0])
        ->and($button->native()->fittingSize()->width)->toBeLessThan(24.0);
    $window->close();
});

it('keeps the file\'s shape, the longer side at the symbol\'s size', function (): void {
    [$window, $button] = iconButton(iconFile(72, 36));
    $size = $button->native()->image()->size();

    expect([$size->width, $size->height])->toBe([16.0, 8.0]);
    $window->close();
});

it('grows the icon with the button\'s font, as a symbol grows', function (): void {
    [$window, $button] = iconButton(iconFile(72, 72));
    $button->setFont(new FontSpec(NSFont::systemFontSize() * 2));
    pumpFor(0.1);
    $size = $button->native()->image()->size();

    expect([$size->width, $size->height])->toBe([32.0, 32.0]);
    $window->close();
});
