<?php

declare(strict_types=1);

use Surface\Contracts\Toolkits\UI\Align;
use Surface\Contracts\Toolkits\UI\ButtonStyle;
use Surface\Contracts\Toolkits\UI\IconPosition;
use Surface\Contracts\Toolkits\UI\Insets;
use Surface\NutsAndBolts\Color;

/** A borderless button with a background, label and trailing arrow, in a window. */
function paddedButton(): array
{
    $window = openWindow('pad', 400, 300);
    $button = $window->column('root')->button('go', 'Continue')
        ->setStyle(ButtonStyle::BORDERLESS)
        ->setBackground(Color::rgb(30, 90, 200))
        ->setSymbol('arrow.right')
        ->setIconPosition(IconPosition::TRAILING)
        ->align(Align::START, Align::START);
    pumpFor(0.1);

    return [$window, $button];
}

function settledSize($button): array
{
    pumpFor(0.1);

    return $button->size();
}

it('is the content plus the padding: 32 wider for 0 16, 16 taller too for 8 16', function (): void {
    [, $button] = paddedButton();
    [$w, $h] = settledSize($button);

    $button->setPadding('0 16');
    expect(settledSize($button))->toBe([$w + 32, $h]);

    $button->setPadding('8 16');
    expect(settledSize($button))->toBe([$w + 32, $h + 16]);

    $button->setPadding(Insets::none());
    expect(settledSize($button))->toBe([$w, $h]);
});

it('keeps the padding when the label, font and icon position change', function (): void {
    [, $button] = paddedButton();
    $button->setPadding('8 16');
    [$w, $h] = settledSize($button);

    $button->setLabel('Continue to the next page');
    [$w2, $h2] = settledSize($button);
    expect($w2)->toBeGreaterThan($w)->and($h2)->toBe($h);

    $button->setPadding(0);
    [$bare] = settledSize($button);
    expect($bare)->toBe($w2 - 32);

    $button->setPadding('8 16')->setIconPosition(IconPosition::LEADING);
    expect(settledSize($button))->toBe([$bare + 32, $h]);
});

it('splits uneven left and right evenly: only the total counts', function (): void {
    [, $button] = paddedButton();
    [$w] = settledSize($button);

    $button->setPadding('0 4 0 28');

    expect(settledSize($button)[0])->toBe($w + 32);
});
