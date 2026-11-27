<?php

declare(strict_types=1);

/*
| A tab view, split view and scroll view take a child as one view. Each holds it in a holder
| view, its edges pinned inside by its margin (PinsInMargin); a hidden child's margin takes no
| room, and a hidden split pane none at all.
*/

/** A primitive's view frame in its superview (the holder): [x, y, width, height]. */
function marginFrame(object $primitive): array
{
    $frame = $primitive->native()->frame();

    return [(int) round($frame->x), (int) round($frame->y), (int) round($frame->width), (int) round($frame->height)];
}

/** The holder's size, the child's superview: [width, height]. */
function holderSize(object $primitive): array
{
    $bounds = $primitive->native()->superview()->bounds();

    return [(int) round($bounds->width), (int) round($bounds->height)];
}

afterEach(fn () => closeAll());

it('keeps a tab page in from its margin, live', function (): void {
    $window = openWindow('main', 600, 400);
    $page = $window->column('root')->tabs('tabs')->fill()->addPage('one', 'One');
    $page->setMargin('4 6 8 10');
    pumpFor(0.4);

    [$x, , $w, $h] = marginFrame($page);
    [$hw, $hh] = holderSize($page);
    expect([$x, $w, $h])->toBe([10, $hw - 16, $hh - 12]);

    $page->setMargin(0);
    pumpFor(0.3);
    expect(array_slice(marginFrame($page), 2))->toBe([$hw, $hh]);
});

it('keeps a split pane in from its margin, live, and gives a hidden pane no room', function (): void {
    $window = openWindow('main', 600, 400);
    $split = $window->column('root')->split('split')->fill();
    $first = $split->column('first');
    $second = $split->column('second')->setMargin(9);
    pumpFor(0.4);

    [$x, , $w, $h] = marginFrame($second);
    [$hw, $hh] = holderSize($second);
    expect([$x, $w, $h])->toBe([9, $hw - 18, $hh - 18]);

    $second->setMargin(0);
    pumpFor(0.3);
    expect(array_slice(marginFrame($second), 2))->toBe(holderSize($second));

    // NSSplitView keeps its divider beside a hidden pane, margin or none.
    $second->hide();
    pumpFor(0.3);
    expect(holderSize($first)[0])->toBe(600 - (int) $split->native()->dividerThickness());
});

it('keeps a scroll view\'s content in from its margin, from the top, live', function (): void {
    $window = openWindow('main', 600, 400);
    $scroll = $window->column('root')->scrollView('scroll')->fill();
    $content = $scroll->column('content')->minSize(0, 800)->setMargin('5 0 0 7');
    pumpFor(0.4);

    // The holder is not flipped: the top margin is between the content's top and the holder's.
    $top = fn (): int => holderSize($content)[1] - (marginFrame($content)[1] + marginFrame($content)[3]);
    expect(marginFrame($content)[0])->toBe(7)
        ->and($top())->toBe(5)
        ->and($scroll->scrollPosition()->y)->toBe(0.0);

    $content->setMargin(0);
    pumpFor(0.3);
    expect(marginFrame($content)[0])->toBe(0)
        ->and($top())->toBe(0);
});
