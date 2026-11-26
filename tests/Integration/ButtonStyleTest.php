<?php

declare(strict_types=1);

use Surface\Contracts\Toolkits\UI\Align;
use Surface\Contracts\Toolkits\UI\ButtonStyle;

it('draws HELP as AppKit\'s help bezel, a square whatever the label', function (): void {
    $window = openWindow('help', 400, 300);
    $button = $window->column('root')->button('help', 'Open the Stargazer page')
        ->setStyle(ButtonStyle::HELP)
        ->align(Align::START, Align::START);
    pumpFor(0.1);

    $native = $button->native();
    $frame = $native->frame();
    expect($native->bezelStyle())->toBe(NSBezelStyle::HELP_BUTTON)
        ->and($native->isBordered())->toBeTrue()
        ->and($frame->width)->toBe($frame->height)
        ->and($native->title())->toBe('Open the Stargazer page');
});

it('goes back to the bezel it was made with when HELP is no longer the style', function (): void {
    $window = openWindow('help', 400, 300);
    $button = $window->column('root')->button('help', '?');
    $made = $button->native()->bezelStyle();

    $button->setStyle(ButtonStyle::HELP);
    $button->setStyle(ButtonStyle::DEFAULT);
    expect($button->native()->bezelStyle())->toBe($made)
        ->and($button->native()->isBordered())->toBeTrue();

    $button->setStyle(ButtonStyle::HELP);
    $button->setStyle(ButtonStyle::BORDERLESS);
    expect($button->native()->bezelStyle())->toBe($made)
        ->and($button->native()->isBordered())->toBeFalse();
});
