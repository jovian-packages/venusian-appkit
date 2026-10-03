<?php

declare(strict_types=1);

use Surface\Contracts\Windows\Mail\View\RowSelected;
use Surface\Contracts\Windows\Primitives\TableColumn;
use Surface\Contracts\Windows\WindowException;

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

it('shows rows, normalises missing cells, and posts RowSelected', function (): void {
    $window = driver()->open('main', 400, 300);
    $table = $window->column('m')->table('t', [new TableColumn('title', 'Title'), new TableColumn('year', 'Year')], [['title' => 'a', 'year' => '1'], ['title' => 'b']]);
    $window->present();
    pumpFor(0.2);
    takeMail(session());                                 // the WindowFocused presenting posts
    $table->selectRow(1);
    pumpFor(0.1);

    expect($table->rows()[1])->toBe(['title' => 'b', 'year' => ''])
        ->and($table->selectedRow())->toBe(1)
        ->and(takeMail(session()))->toEqual([])            // selectRow from code posts nothing
        ->and(fn () => $table->selectRow(5))->toThrow(WindowException::class);
    $table->native()->documentView()->selectRowIndexesByExtendingSelection(NSIndexSet::indexSetWithIndex(0), false);
    pumpFor(0.1);
    expect(takeMail(session()))->toEqual([new RowSelected('main', 'm.t', $table->uuid(), 0, ['title' => 'a', 'year' => '1'])]);
    $table->appendRow(['title' => 'c', 'year' => '3']);
    expect($table->native()->documentView()->numberOfRows())->toBe(3);
});

it('builds a column per TableColumn and shows each normalised cell', function (): void {
    $window = driver()->open('main', 400, 300);
    $table = $window->column('m')->table('t', [new TableColumn('title', 'Title'), new TableColumn('year', 'Year')], [['title' => 'a', 'year' => 1999], ['title' => 'b']]);
    $window->present();
    pumpFor(0.2);
    $view = $table->native()->documentView();

    expect($table->native())->toBeInstanceOf(NSScrollView::class)
        ->and($view)->toBeInstanceOf(NSTableView::class)
        ->and(array_map(fn (NSTableColumn $c) => [$c->identifier(), $c->title()], $view->tableColumns()))->toBe([['title', 'Title'], ['year', 'Year']])
        ->and($view->preparedCellAtColumnRow(1, 0)->objectValue())->toBe('1999')
        ->and($view->preparedCellAtColumnRow(1, 1)->objectValue())->toBe('');
});

it('clears the selection with setRows and selectRow(null), reports a deselect, and disables natively', function (): void {
    $window = driver()->open('main', 400, 300);
    $main = $window->column('m');
    $table = $main->table('t', [new TableColumn('n', 'N')], [['n' => 'x'], ['n' => 'y']]);
    $window->present();
    pumpFor(0.2);
    takeMail(session());
    $view = $table->native()->documentView();

    $table->selectRow(1);
    expect($view->selectedRow())->toBe(1);
    $table->selectRow(null);
    expect($view->selectedRow())->toBe(-1);
    $table->selectRow(0)->setRows([['n' => 'z']]);
    expect($table->selectedRow())->toBeNull()
        ->and($view->selectedRow())->toBe(-1)
        ->and($view->numberOfRows())->toBe(1)
        ->and(takeMail(session()))->toBe([]);

    $view->selectRowIndexesByExtendingSelection(NSIndexSet::indexSetWithIndex(0), false);
    $view->deselectAll(null);
    expect(takeMail(session()))->toEqual([
        new RowSelected('main', 'm.t', $table->uuid(), 0, ['n' => 'z']),
        new RowSelected('main', 'm.t', $table->uuid(), null, null),
    ]);

    $main->disable();
    expect($view->isEnabled())->toBeFalse();
    $table->clearRows();
    expect($view->numberOfRows())->toBe(0);
});

it('shows its rows at their natural height when it does not fill', function (): void {
    $window = driver()->open('main', 400, 300);
    $table = $window->column('m')->table('t', [new TableColumn('n', 'N')], [['n' => 'a'], ['n' => 'b'], ['n' => 'c']]);
    $window->present();
    pumpFor(0.2);
    $height = $table->size()[1];

    expect($height)->toBeGreaterThan(50);
    $table->appendRow(['n' => 'd']);
    pumpFor(0.1);
    expect($table->size()[1])->toBeGreaterThan($height);
});

it('paints its background on the table view', function (): void {
    $window = driver()->open('main', 400, 300);
    $table = $window->column('m')->table('t', [new TableColumn('n', 'N')], [['n' => 'a']]);
    $built = $table->native()->documentView()->backgroundColor();
    $table->setBackground(Surface\NutsAndBolts\Color::hex('#ff0000'));
    expect(round($table->native()->documentView()->backgroundColor()->redComponent(), 2))->toBe(1.0);
    $table->setBackground(null);
    expect($table->native()->documentView()->backgroundColor())->toEqual($built);
});
