<?php

declare(strict_types=1);

use Surface\Contracts\Windows\Mail\View\ItemActionClicked;
use Surface\Contracts\Windows\Mail\View\ItemActivated;
use Surface\Contracts\Windows\Mail\View\SelectionChanged;
use Surface\Contracts\Windows\Primitives\CollectionItem;
use Surface\Contracts\Windows\Primitives\ListRow;

// No window is presented: each view is built, fed and clicked in an unshown window.

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

/** A protected method of $object, called as the native would. */
function callNative(object $object, string $method, mixed ...$args): mixed
{
    return Closure::bind(fn () => $this->{$method}(...$args), $object, $object::class)();
}

function thumbFile(): string
{
    $file = sys_get_temp_dir().'/surface-item-thumb.png';
    if (! is_file($file)) {
        $image = imagecreatetruecolor(30, 20);
        imagefill($image, 0, 0, imagecolorallocate($image, 200, 40, 40));
        imagepng($image, $file);
    }

    return $file;
}

it('shows its segments and posts SelectionChanged for a user choice only', function (): void {
    $window = driver()->open('main', 400, 200);
    $control = $window->column('m')->segmentedControl('s', ['Today', 'Recently', 'APODdex'], 0);
    $native = $control->native();

    expect($native->segmentCount())->toBe(3)
        ->and($native->labelForSegment(1))->toBe('Recently')
        ->and($native->selectedSegment())->toBe(0);

    $control->setSegment(0, 'Yesterday')->select(2);
    expect($native->labelForSegment(0))->toBe('Yesterday')
        ->and($native->selectedSegment())->toBe(2)
        ->and(takeMail(session()))->toBe([]);

    $native->setSelectedSegment(1);
    callNative($control, 'chosen');
    expect(takeMail(session()))->toEqual([new SelectionChanged('main', 'm.s', $control->uuid(), 1, 'Recently')])
        ->and($control->selected())->toBe(1);
});

it('builds collection cells from the items, sizes them, and splices items', function (): void {
    $window = driver()->open('main', 600, 400);
    $view = $window->column('m')->collectionView('c', [
        new CollectionItem(thumbFile(), 'June 16, 1995', thumbFile()),
        new CollectionItem(null, 'June 17, 1995'),
    ]);
    $view->setCellSize(220, 156);
    $collection = $view->focusView();

    expect($view->native())->toBeInstanceOf(NSScrollView::class)
        ->and($collection->numberOfItemsInSection(0))->toBe(2);

    $first = callNative($view, 'cellAt', NSIndexPath::indexPathForItemInSection(0, 0));
    $cell = callNative($view, 'cellsFor', $first);
    expect($cell['caption']->stringValue())->toBe('June 16, 1995')
        ->and($cell['action']->isHidden())->toBeFalse()
        ->and($cell['image']->layer()->contents())->not->toBeNull()
        ->and($first->view()->frame()->width)->toEqualWithDelta(220.0, 0.01);

    $second = callNative($view, 'cellAt', NSIndexPath::indexPathForItemInSection(1, 0));
    expect(callNative($view, 'cellsFor', $second)['action']->isHidden())->toBeTrue();

    $view->insertItems(0, [new CollectionItem(null, 'new')])->removeItems(0);
    expect($collection->numberOfItemsInSection(0))->toBe(2);

    // New cells ask for the next-turn relayout, which leaves the item size at the cell size.
    expect((new ReflectionProperty($view, 'relayout'))->getValue($view))->not->toBeNull();
    pumpFor(0.05);
    $size = $collection->collectionViewLayout()->itemSize();
    expect((new ReflectionProperty($view, 'relayout'))->getValue($view))->toBeNull()
        ->and([$size->width, $size->height])->toBe([220.0, 156.0]);
});

it('floats a cell\'s status icons on discs over its top-right corner, left of the action button', function (): void {
    $window = driver()->open('main', 600, 400);
    $view = $window->column('m')->collectionView('c', [
        new CollectionItem(thumbFile(), 'a', thumbFile(), [thumbFile(), thumbFile()]),
        new CollectionItem(thumbFile(), 'b', null, [thumbFile()]),
    ]);
    $view->setCellSize(220, 156);
    $cell = fn (int $i) => callNative($view, 'cellsFor', callNative($view, 'cellAt', NSIndexPath::indexPathForItemInSection($i, 0)));

    $a = $cell(0);
    $icons = $a['icons']->frame();
    $action = $a['action']->frame();
    expect($a['icons']->subviews())->toHaveCount(2)
        ->and($a['icons']->subviews()[0]->subviews()[1]->layer()->contents())->not->toBeNull()
        // Left of the action button, the discs' tops level with the corner's.
        ->and($icons->x + $icons->width)->toBeLessThanOrEqual($action->x)
        ->and($icons->y + $icons->height)->toEqualWithDelta(156.0 - 6.0, 0.01);

    // No action button: the discs move to the corner.
    $b = $cell(1)['icons']->frame();
    expect($b->x + $b->width)->toEqualWithDelta(220.0 - 6.0, 0.01);

    $view->updateItem(0, new CollectionItem(thumbFile(), 'a'));
    expect($cell(0)['icons']->subviews())->toBe([]);
});

it('puts a list row\'s status icons at the title line\'s trailing end', function (): void {
    $window = driver()->open('main', 600, 400);
    $view = $window->column('m')->listView('l', [new ListRow(null, 'Short', '', null, [thumbFile()])]);
    $table = $view->focusView();
    $table->reloadData();
    $row = callNative($view, 'rowsFor', $table->viewAtColumnRowMakeIfNecessary(0, 0, true));
    $row['view']->setFrame(new NSRect(0.0, 0.0, 500.0, 64.0));
    $row['view']->layoutSubtreeIfNeeded();
    $icons = $row['icons']->frame();

    expect($icons->x + $icons->width)->toEqualWithDelta(500.0 - 12.0, 0.5)
        ->and($icons->x)->toBeGreaterThan($row['title']->frame()->x + $row['title']->frame()->width + 6.0);
});

it('builds list rows with badge, icons, detail and action, dims disabled rows, and posts for enabled rows only', function (): void {
    $window = driver()->open('main', 600, 400);
    $view = $window->column('m')->listView('l', [
        new ListRow(thumbFile(), 'June 16, 1995 · Neutron Star', 'A detail line.', 'NEW', [thumbFile(), thumbFile()], 'Open ›'),
        new ListRow(null, 'June 17, 1995 · ???', enabled: false),
    ]);
    $view->setRowHeight(104);
    $table = $view->focusView();
    $table->reloadData();

    $open = $table->viewAtColumnRowMakeIfNecessary(0, 0, true);
    $row = callNative($view, 'rowsFor', $open);
    expect($table->rowHeight())->toEqualWithDelta(104.0, 0.01)
        ->and($row['title']->stringValue())->toBe('June 16, 1995 · Neutron Star')
        ->and($row['badge']->stringValue())->toBe(' NEW ')
        ->and($row['badge']->isHidden())->toBeFalse()
        ->and($row['icons']->arrangedSubviews())->toHaveCount(2)
        ->and($row['detail']->stringValue())->toBe('A detail line.')
        ->and($row['action']->title())->toBe('Open ›')
        ->and($open->alphaValue())->toEqualWithDelta(1.0, 0.01);

    $locked = $table->viewAtColumnRowMakeIfNecessary(0, 1, true);
    expect(callNative($view, 'rowsFor', $locked)['action']->isHidden())->toBeTrue()
        ->and($locked->alphaValue())->toEqualWithDelta(0.5, 0.01);

    callNative($view, 'activate', 0);
    callNative($view, 'activate', 1);
    callNative($view, 'actionClicked', $open->pointer());
    expect(takeMail(session()))->toEqual([
        new ItemActivated('main', 'm.l', $view->uuid(), 0),
        new ItemActionClicked('main', 'm.l', $view->uuid(), 0),
    ]);

    $view->removeItems(1);
    expect($table->numberOfRows())->toBe(1);
});
