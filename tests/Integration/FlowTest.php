<?php

declare(strict_types=1);

/*
| A flow places its children itself, from each one's fitting size: side by side, wrapping onto
| the next line where its width runs out, in from its padding and each child's margin.
*/

/**
 * A child's box in the flow, from the flow's top-left: [x, y, width, height].
 */
function flowBox(object $flow, object $child): array
{
    $frame = $child->native()->frame();
    $height = $flow->native()->frame()->height;

    return [(int) round($frame->x), (int) round($height - ($frame->y + $frame->height)), (int) round($frame->width), (int) round($frame->height)];
}

/** A flow of $count 80 x 30 children named c0, c1, ..., in a window $width wide. */
function flowOf(int $count, int $width, int $spacing = 10, int|string $padding = 5): object
{
    $window = openWindow('main', $width, 400);
    $flow = $window->column('root')->flow('tags', $spacing, $padding);
    for ($i = 0; $i < $count; $i++) {
        $flow->column("c{$i}")->minSize(80, 30);
    }
    pumpFor(0.4);

    return $flow;
}

afterEach(fn () => closeAll());

it('puts children side by side from the left at their own size, in from the padding', function (): void {
    $flow = flowOf(3, 600);

    expect(flowBox($flow, $flow->view('c0')))->toBe([5, 5, 80, 30])
        ->and(flowBox($flow, $flow->view('c1')))->toBe([95, 5, 80, 30])
        ->and(flowBox($flow, $flow->view('c2')))->toBe([185, 5, 80, 30])
        ->and($flow->size())->toBe([600, 40]);
});

it('wraps onto the next line where the width runs out, a spacing below', function (): void {
    $flow = flowOf(3, 200);

    expect(flowBox($flow, $flow->view('c0')))->toBe([5, 5, 80, 30])
        ->and(flowBox($flow, $flow->view('c1')))->toBe([95, 5, 80, 30])
        ->and(flowBox($flow, $flow->view('c2')))->toBe([5, 45, 80, 30])
        ->and($flow->size())->toBe([200, 80]);
});

it('grows and shrinks its height with the lines, and moves what is below it', function (): void {
    $window = openWindow('main', 600, 400);
    $root = $window->column('root');
    $flow = $root->flow('tags', 10, 5);
    for ($i = 0; $i < 6; $i++) {
        $flow->column("c{$i}")->minSize(80, 30);
    }
    $below = $root->column('below')->minSize(10, 10);
    pumpFor(0.4);
    $wide = $flow->size()[1];
    $belowWide = (int) round($below->native()->frame()->y);

    nativeResize($window, 200, 400);
    pumpFor(0.5);
    $narrow = $flow->size()[1];
    $belowNarrow = (int) round($below->native()->frame()->y);

    expect($wide)->toBe(40)
        ->and($narrow)->toBe(5 + 30 + 10 + 30 + 10 + 30 + 5)
        ->and($belowWide - $belowNarrow)->toBe($narrow - $wide);

    nativeResize($window, 600, 400);
    pumpFor(0.5);
    expect($flow->size()[1])->toBe($wide);
});

it('keeps each child\'s margin inside its box, and the spacing between boxes', function (): void {
    $flow = flowOf(2, 600, 10, 0);
    $flow->view('c0')->setMargin('4 6 8 10');
    pumpFor(0.4);

    expect(flowBox($flow, $flow->view('c0')))->toBe([10, 4, 80, 30])
        ->and(flowBox($flow, $flow->view('c1')))->toBe([10 + 80 + 6 + 10, 0, 80, 30])
        ->and($flow->size()[1])->toBe(4 + 30 + 8);
});

it('gives a hidden child no room, and takes it back when shown', function (): void {
    $flow = flowOf(3, 600);

    $flow->view('c1')->hide();
    pumpFor(0.3);
    expect(flowBox($flow, $flow->view('c2'))[0])->toBe(95);

    $flow->view('c1')->show();
    pumpFor(0.3);
    expect(flowBox($flow, $flow->view('c2'))[0])->toBe(185);
});

it('lays the children out again when spacing and padding change', function (): void {
    $flow = flowOf(2, 600);

    $flow->setSpacing(20)->setPadding('2 0 0 7');
    pumpFor(0.3);

    expect(flowBox($flow, $flow->view('c0')))->toBe([7, 2, 80, 30])
        ->and(flowBox($flow, $flow->view('c1')))->toBe([107, 2, 80, 30])
        ->and($flow->size()[1])->toBe(32);
});

it('follows the order of its children when one moves', function (): void {
    $flow = flowOf(3, 600);

    $flow->view('c2')->moveBefore($flow->view('c0'));
    pumpFor(0.3);

    expect(flowBox($flow, $flow->view('c2'))[0])->toBe(5)
        ->and(flowBox($flow, $flow->view('c0'))[0])->toBe(95)
        ->and(flowBox($flow, $flow->view('c1'))[0])->toBe(185);
});

it('wraps again when a child is removed or added', function (): void {
    $flow = flowOf(3, 200);

    $flow->view('c1')->remove();
    pumpFor(0.3);
    expect(flowBox($flow, $flow->view('c2')))->toBe([95, 5, 80, 30])
        ->and($flow->size()[1])->toBe(40);

    $flow->column('c3')->minSize(80, 30);
    pumpFor(0.3);
    expect(flowBox($flow, $flow->view('c3')))->toBe([5, 45, 80, 30]);
});

it('gives each control its own natural size', function (): void {
    $window = openWindow('main', 600, 400);
    $flow = $window->column('root')->flow('tags', 8, 0);
    $flow->button('short', 'OK');
    $flow->button('long', 'A much longer title');
    pumpFor(0.4);

    [$x0, , $w0] = flowBox($flow, $flow->view('short'));
    [$x1, , $w1] = flowBox($flow, $flow->view('long'));

    expect($w0)->toBeGreaterThan(0)
        ->and($w1)->toBeGreaterThan($w0)
        ->and($x1)->toBe($x0 + $w0 + 8);
});
