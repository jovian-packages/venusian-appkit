<?php

declare(strict_types=1);

use NSLineBreakMode;

/*
| A label that truncates is one line cut at the tail at the width it is given: a long text
| neither widens the window nor its column.
*/

const TRUNCATE_LONG = 'A title far longer than the narrow window it is shown in, so it must be cut';

afterEach(fn () => closeAll());

it('cuts a long title at the window\'s width instead of widening it', function (): void {
    $window = openWindow('main', 200, 120);
    $label = $window->column('root')->label('title', TRUNCATE_LONG)->setTruncate(true);
    pumpFor(0.4);

    // A text field's frame reaches 2 pt past its alignment rect on each side, the rect
    // constraints place: at x -2 and 204 wide, the label is exactly the column's 200.
    $native = $label->native();
    expect($window->size()[0])->toBe(200)
        ->and((int) round($native->superview()->frame()->width))->toBe(200)
        ->and([(int) round($native->frame()->x), (int) round($native->frame()->width)])->toBe([-2, 204])
        ->and((int) round($native->frame()->height))->toBeLessThan(30)
        ->and($native->lineBreakMode())->toBe(NSLineBreakMode::TRUNCATING_TAIL)
        ->and($native->maximumNumberOfLines())->toBe(1);
});

it('wins over wrap, and gives the wrap back when it stops', function (): void {
    $window = openWindow('main', 200, 300);
    $label = $window->column('root')->label('title', TRUNCATE_LONG)->setWrap(true)->setTruncate(true);
    pumpFor(0.4);
    $oneLine = (int) round($label->native()->frame()->height);

    $label->setTruncate(false);
    pumpFor(0.4);

    expect($label->native()->lineBreakMode())->toBe(NSLineBreakMode::WORD_WRAPPING)
        ->and((int) round($label->native()->frame()->height))->toBeGreaterThan($oneLine * 2)
        ->and($window->size()[0])->toBe(200);
});

it('needs the cut: the same text unwrapped and whole is wider than the window', function (): void {
    $window = openWindow('main', 200, 120);
    $label = $window->column('root')->label('title', TRUNCATE_LONG);
    pumpFor(0.4);

    expect($label->native()->fittingSize()->width)->toBeGreaterThan(200.0);
});
