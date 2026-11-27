<?php

declare(strict_types=1);

/** A 72 x 72 pixel file at 72 dpi: AppKit alone would draw it at 72 points. */
function dropdownIconFile(): string
{
    $path = sys_get_temp_dir().'/appkit-dropdown-icon.png';
    imagepng(imagecreatetruecolor(72, 72), $path);

    return $path;
}

function kindDropdown(): array
{
    $window = openWindow('dropdown', 400, 300);
    $dropdown = $window->column('root')->dropdown('kind', ['All', 'Images', 'Videos', 'Audio'], 0);
    pumpFor(0.05);

    return [$window, $dropdown];
}

/** @return list<NSImage|null> each menu item's image */
function itemImages(object $dropdown): array
{
    $menu = $dropdown->native()->menu();

    return array_map(fn (int $i): ?NSImage => $menu->itemAtIndex($i)->image(), range(0, $menu->numberOfItems() - 1));
}

it('gives each option its icon: the file at a symbol\'s size, else the SF Symbol, else none', function (): void {
    [$window, $dropdown] = kindDropdown();
    $dropdown->setIcons([['symbol' => 'magnifyingglass'], ['file' => dropdownIconFile(), 'symbol' => 'photo'], null]);
    $images = itemImages($dropdown);

    expect($images[0])->toBeInstanceOf(NSImage::class)
        ->and([$images[1]->size()->width, $images[1]->size()->height])->toBe([16.0, 16.0])
        ->and($images[2])->toBeNull()
        ->and($images[3])->toBeNull()
        ->and($dropdown->native()->itemTitles())->toBe(['All', 'Images', 'Videos', 'Audio']);
    $window->close();
});

it('gives rebuilt options their icons again', function (): void {
    [$window, $dropdown] = kindDropdown();
    $dropdown->setIcons([['symbol' => 'magnifyingglass'], ['symbol' => 'photo']]);
    $dropdown->setOptions(['All', 'Images', 'Videos']);
    $images = itemImages($dropdown);

    expect($images[0])->toBeInstanceOf(NSImage::class)
        ->and($images[1])->toBeInstanceOf(NSImage::class)
        ->and($images[2])->toBeNull();
    $window->close();
});

it('shows the selected option\'s icon alone, borderless, and its label again once it is not icon-only', function (): void {
    [$window, $dropdown] = kindDropdown();
    $popup = $dropdown->native();
    $dropdown->setIcons([['symbol' => 'magnifyingglass'], ['symbol' => 'photo'], ['symbol' => 'video'], ['symbol' => 'headphones']]);
    $labelled = [$popup->fittingSize()->width, $popup->imagePosition(), $popup->isBordered()];

    $dropdown->setIconOnly();
    $dropdown->select(2);
    pumpFor(0.05);
    $iconOnly = $popup->fittingSize()->width;

    expect($popup->isBordered())->toBeFalse()
        ->and($popup->imagePosition())->toBe(NSCellImagePosition::IMAGE_ONLY)
        ->and($popup->image()?->pointer())->toBe($popup->menu()->itemAtIndex(2)->image()->pointer())
        ->and($iconOnly)->toBeLessThan($labelled[0]);

    $dropdown->setIconOnly(false);

    expect([$popup->fittingSize()->width, $popup->imagePosition(), $popup->isBordered()])->toBe($labelled);
    $window->close();
});
