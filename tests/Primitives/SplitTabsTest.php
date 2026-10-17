<?php

declare(strict_types=1);

use Jovian\Toolkits\Appkit\Primitives\AppkitSplitView;
use Jovian\Toolkits\Appkit\Windows\AppkitWindow;
use Surface\Contracts\Windows\Mail\View\SplitMoved;
use Surface\Contracts\Windows\Mail\View\TabSelected;

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

/** Pump until the window is on screen and two pumps see the same split length and pane frames. */
function settledSplit(AppkitWindow $window, AppkitSplitView $split, float $seconds = 3.0): bool
{
    $last = null;

    return pumpUntil(function () use ($window, $split, &$last): bool {
        $now = [$split->native()->bounds()->width, $split->native()->bounds()->height];
        foreach ($split->native()->arrangedSubviews() as $view) {
            $frame = $view->frame();
            array_push($now, $frame->x, $frame->y, $frame->width, $frame->height);
        }
        $settled = $window->native()->isVisible() && $now[0] > 0.0 && $now === $last;
        $last = $now;

        return $settled;
    }, $seconds);
}

/** A three-pane split filling a 600×300 window. */
function threePanes(bool $horizontal = true): array
{
    $window = driver()->open('main', 600, 300);
    $split = $window->column('m')->split('panes', $horizontal)->fill();
    foreach (['a', 'b', 'c'] as $pane) {
        $split->column($pane)->label("{$pane}-label", strtoupper($pane));
    }

    return [$window, $split];
}

/** Where a point in the split lands in its window's coordinates (origin bottom left). */
function inWindow(AppkitWindow $window, AppkitSplitView $split, float $x, float $y): NSPoint
{
    $content = $window->native()->contentView();
    $point = $content->convertPointFromView(new NSPoint($x, $y), $split->native());

    return $content->isFlipped() ? new NSPoint($point->x, $content->bounds()->height - $point->y) : $point;
}

function mouse(AppkitWindow $window, NSEventType $type, NSPoint $at): void
{
    session()->application()->postEventAtStart(NSEvent::mouseEventWithTypeLocationModifierFlagsTimestampWindowNumberContextEventNumberClickCountPressure(
        $type, $at, 0, 0.0, $window->native()->windowNumber(), null, 0, 1, 1.0,
    ), false);
}

it('round-trips divider positions within a divider of the split length, and posts nothing for them', function (bool $horizontal): void {
    [$window, $split] = threePanes($horizontal);
    $window->present();
    expect(settledSplit($window, $split))->toBeTrue();
    $length = $horizontal ? $split->native()->bounds()->width : $split->native()->bounds()->height;
    $tolerance = (1.0 + $split->native()->dividerThickness()) / $length;
    expect(abs($split->positions()[0] - 1 / 3))->toBeLessThanOrEqual($tolerance)   // the first layout shares the length
        ->and(abs($split->positions()[1] - 2 / 3))->toBeLessThanOrEqual($tolerance);

    $split->setPositions([0.25, 0.6]);
    expect(settledSplit($window, $split))->toBeTrue();
    $positions = $split->positions();
    $views = $split->native()->arrangedSubviews();
    $first = $views[0]->frame();

    expect($positions)->toHaveCount(2)
        ->and(abs($positions[0] - 0.25))->toBeLessThanOrEqual($tolerance)
        ->and(abs($positions[1] - 0.6))->toBeLessThanOrEqual($tolerance)
        ->and(abs(($horizontal ? $first->width : $first->height) / $length - $positions[0]))->toBeLessThanOrEqual(1.0 / $length)
        ->and(viewMail(session()))->toBe([]);
})->with(['side by side' => true, 'stacked' => false]);

it('applies positions set before the split is on screen at its first layout', function (): void {
    [$window, $split] = threePanes();
    $split->setPositions([0.3, 0.7]);
    $window->present();
    expect(settledSplit($window, $split))->toBeTrue();
    $tolerance = (1.0 + $split->native()->dividerThickness()) / $split->native()->bounds()->width;

    expect(abs($split->positions()[0] - 0.3))->toBeLessThanOrEqual($tolerance)
        ->and(abs($split->positions()[1] - 0.7))->toBeLessThanOrEqual($tolerance);
});

it('follows the visible panes when the middle one hides, and shows it again', function (): void {
    [$window, $split] = threePanes();
    $window->present();
    expect(settledSplit($window, $split))->toBeTrue();

    $split->view('b')->hide();
    expect(settledSplit($window, $split))->toBeTrue()
        ->and($split->positions())->toHaveCount(1);
    $split->setPositions([0.5]);
    expect(settledSplit($window, $split))->toBeTrue();
    $tolerance = (1.0 + $split->native()->dividerThickness()) / $split->native()->bounds()->width;
    expect(abs($split->positions()[0] - 0.5))->toBeLessThanOrEqual($tolerance)
        ->and($split->native()->isSubviewCollapsed($split->native()->arrangedSubviews()[1]))->toBeTrue();

    $split->view('b')->show();
    expect(settledSplit($window, $split))->toBeTrue()
        ->and($split->positions())->toHaveCount(2)
        ->and(viewMail(session()))->toBe([]);
});

it('posts nothing when a window resize moves the dividers', function (): void {
    [$window, $split] = threePanes();
    $window->present();
    expect(settledSplit($window, $split))->toBeTrue();
    $split->setPositions([0.25, 0.6]);
    expect(settledSplit($window, $split))->toBeTrue();
    viewMail(session());

    $window->native()->setContentSize(new NSSize(900, 400));
    expect(settledSplit($window, $split))->toBeTrue()
        ->and($split->native()->bounds()->width)->toBeGreaterThan(600.0)
        ->and(viewMail(session()))->toBe([]);
});

it('posts one SplitMoved when a user drag of a divider ends, and none for a click off the dividers', function (): void {
    [$window, $split] = threePanes();
    $window->present();
    expect(settledSplit($window, $split))->toBeTrue();
    $split->setPositions([0.25, 0.6]);
    expect(settledSplit($window, $split))->toBeTrue();
    viewMail(session());

    $first = $split->native()->arrangedSubviews()[0]->frame();
    $x = $first->x + $first->width + $split->native()->dividerThickness() / 2;
    $y = $split->native()->bounds()->height / 2;
    mouse($window, NSEventType::LEFT_MOUSE_DOWN, inWindow($window, $split, $x, $y));
    mouse($window, NSEventType::LEFT_MOUSE_DRAGGED, inWindow($window, $split, $x + 50, $y));
    mouse($window, NSEventType::LEFT_MOUSE_UP, inWindow($window, $split, $x + 50, $y));
    pumpFor(0.3);
    $mail = viewMail(session());

    expect($mail)->toHaveCount(1)
        ->and($mail[0])->toBeInstanceOf(SplitMoved::class)
        ->and($mail[0]->name())->toBe('view.split-moved.main.m.panes')
        ->and($mail[0]->positions)->toBe($split->positions())
        ->and($split->positions()[0])->toBeGreaterThan(0.25 + 40 / 600);

    mouse($window, NSEventType::LEFT_MOUSE_DOWN, inWindow($window, $split, 20, $y));
    mouse($window, NSEventType::LEFT_MOUSE_UP, inWindow($window, $split, 20, $y));
    pumpFor(0.2);
    expect(viewMail(session()))->toBe([]);
});

it('titles, selects and removes pages without mail, and posts TabSelected for a user switch', function (): void {
    $window = driver()->open('main', 400, 300);
    $tabs = $window->column('m')->tabs('sessions')->fill();
    $tabs->addPage('one', 'left.txt ↔ right.txt')->label('l1', 'One');
    $tabs->addPage('two', 'Folders')->label('l2', 'Two');
    $tabs->addPage('three', 'Three');
    $window->present();
    pumpFor(0.2);

    $native = $tabs->native();
    expect($tabs->pages())->toBe(['one', 'two', 'three'])
        ->and($tabs->selected())->toBe('one')
        ->and($native->numberOfTabViewItems())->toBe(3);

    $tabs->setTitle('two', 'Folders *')->select('two');
    expect($native->tabViewItems()[1]->label())->toBe('Folders *')
        ->and($tabs->selected())->toBe('two')
        ->and($native->selectedTabViewItem()->identifier())->toBe('two');

    $tabs->view('two')->remove();
    expect($tabs->pages())->toBe(['one', 'three'])
        ->and($native->numberOfTabViewItems())->toBe(2)
        ->and($tabs->selected())->not->toBeNull()
        ->and(viewMail(session()))->toBe([]);

    $native->selectTabViewItemWithIdentifier('three');
    $mail = viewMail(session());
    expect($mail)->toHaveCount(1)
        ->and($mail[0])->toBeInstanceOf(TabSelected::class)
        ->and($mail[0]->page)->toBe('three')
        ->and($mail[0]->name())->toBe('view.tab-selected.main.m.sessions')
        ->and($tabs->selected())->toBe('three');
});
