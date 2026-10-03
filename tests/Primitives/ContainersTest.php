<?php

declare(strict_types=1);

use Surface\Contracts\Windows\Mail\WindowResized;
use Surface\Contracts\Windows\Primitives\Align;
use Surface\Contracts\Windows\WindowException;
use Surface\NutsAndBolts\Color;

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

it('mounts a column as the window content and lays children out natively', function (): void {
    $window = driver()->open('main', 400, 300);
    $main = $window->column('main', spacing: 8, padding: 10);
    $title = $main->label('title', 'Hello')->align(Align::CENTER);
    $body = $main->row('body', spacing: 4);
    $left = $body->button('left', 'L')->fill(vertical: false);
    $window->present();
    pumpFor(0.2);

    expect($main->native())->toBeInstanceOf(NSStackView::class)
        ->and($main->native()->superview())->toBe($window->native()->contentView())
        ->and($main->native()->arrangedSubviews())->toBe([$title->native(), $body->native()])
        ->and($main->size()[0])->toBe(400)
        ->and($window->size())->toBe([400, 300])
        ->and($left->size()[0])->toBeGreaterThan(300)
        ->and($title->size()[0])->toBeLessThan(200);
});

it('fills grid cells and absolute frames', function (): void {
    $window = driver()->open('main', 400, 300);
    $grid = $window->grid('g', spacing: 2);
    $a = $grid->at(0, 0, 1, 2)->label('a', 'A');
    $b = $grid->at(1, 1)->button('b', 'B');
    $fixed = $grid->at(2, 0)->fixed('f');
    $c = $fixed->at(10, 20, 30, 40)->label('c', 'C');
    $window->present();
    pumpFor(0.2);

    expect($grid->native()->cellAtColumnIndexRowIndex(0, 0)->contentView())->toBe($a->native())
        ->and($grid->native()->cellAtColumnIndexRowIndex(1, 1)->contentView())->toBe($b->native())
        ->and($c->native()->frame()->width)->toBe(30.0)
        ->and($c->size())->toBe([30, 40]);
    $fixed->move($c, 15, 25)->resize($c, 50, 60);
    expect($c->size())->toBe([50, 60]);
});

it('posts WindowResized once per pump with the last size', function (): void {
    $window = driver()->open('main', 400, 300)->present();
    pumpFor(0.2);
    takeMail(session());
    $window->native()->setContentSize(new NSSize(420.0, 300.0));
    $window->native()->setContentSize(new NSSize(440.0, 310.0));
    pumpFor(0.1);

    $resized = array_values(array_filter(takeMail(session()), fn ($m) => $m instanceof WindowResized));
    expect($resized)->toEqual([new WindowResized('main', 440, 310)])
        ->and($window->size())->toBe([440, 310]);
});

it('removes the content tree when the window closes', function (): void {
    $window = driver()->open('main', 400, 300);
    $label = $window->column('main')->label('a', 'A');
    $window->close();
    pumpFor(0.05);

    expect($label->isRemoved())->toBeTrue()
        ->and(fn () => $window->view('main.a'))->toThrow(WindowException::class, 'closed');
});

it('places fixed children from the top-left and keeps them there when the fixed grows', function (): void {
    $window = driver()->open('main', 300, 200);
    $fixed = $window->fixed('f');
    $c = $fixed->at(10, 20, 30, 40)->label('c', 'C');
    $window->present();
    pumpFor(0.2);

    $height = $fixed->native()->frame()->height;
    expect($c->native()->frame()->x)->toBe(10.0)
        ->and($c->native()->frame()->y)->toBe($height - 20.0 - 40.0);

    $window->native()->setContentSize(new NSSize(300.0, 260.0));
    pumpFor(0.1);
    expect($fixed->native()->frame()->height)->toBe(260.0)
        ->and($c->native()->frame()->y)->toBe(260.0 - 20.0 - 40.0)
        ->and($fixed->frameOf($c)->y)->toBe(20);

    $c->minSize(80, 10);
    expect($c->size())->toBe([80, 40]);
});

it('sizes a fixed inside a column to the frames it holds', function (): void {
    $window = driver()->open('main', 300, 200);
    $fixed = $window->column('m')->fixed('f');
    $fixed->at(10, 20, 30, 40)->label('c', 'C');
    $fixed->at(50, 5, 20, 10)->label('d', 'D');
    $window->present();
    pumpFor(0.2);

    expect($fixed->size()[1])->toBe(60);
});

it('scrolls one container that keeps its top in view and its width to the viewport', function (): void {
    $window = driver()->open('main', 200, 200);
    $scroll = $window->column('m')->scrollView('s')->fill();
    $lines = $scroll->column('lines');
    foreach (range(0, 29) as $i) {
        $lines->label("l{$i}", "line {$i}");
    }
    $window->present();
    pumpFor(0.2);

    $document = $scroll->native()->documentView();
    expect($scroll->native())->toBeInstanceOf(NSScrollView::class)
        ->and($document)->toBe($lines->native())
        ->and($scroll->size())->toBe([200, 200])
        ->and($lines->size()[0])->toBe((int) $scroll->native()->contentSize()->width)
        ->and($lines->size()[1])->toBeGreaterThan(200)
        ->and($document->visibleRect()->y + $document->visibleRect()->height)->toBe($document->frame()->height);

    $scroll->setScrollbars(false, true);
    expect($scroll->native()->hasHorizontalScroller())->toBeFalse()
        ->and($scroll->native()->hasVerticalScroller())->toBeTrue()
        ->and(fn () => $scroll->row('second'))->toThrow(WindowException::class);
});

it('rebuilds the grid when a spanning child goes, so a later child takes only its own cell', function (): void {
    $window = driver()->open('main', 400, 300);
    $main = $window->column('m');
    $grid = $main->grid('g');
    $wide = $grid->at(0, 0, 1, 2)->label('wide', 'Wide');
    $other = $grid->at(1, 0)->label('other', 'Other');
    $after = $main->label('after', 'After');
    $window->present();
    pumpFor(0.1);
    $before = $grid->native();

    $wide->remove();
    $right = $grid->at(0, 1)->label('right', 'Right');
    pumpFor(0.1);

    $native = $grid->native();
    expect($native)->not->toBe($before)
        ->and($native->superview())->toBe($grid->placedView())
        ->and($main->native()->arrangedSubviews())->toBe([$grid->placedView(), $after->native()])
        ->and($native->cellAtColumnIndexRowIndex(1, 0)->contentView())->toBe($right->native())
        ->and($native->cellAtColumnIndexRowIndex(0, 0)->contentView())->toBeNull()
        ->and($native->cellAtColumnIndexRowIndex(0, 1)->contentView())->toBe($other->native());
});

it('lets a child deep inside take the spare height through every container above it', function (): void {
    $window = driver()->open('main', 300, 300);
    $main = $window->column('m');
    $top = $main->label('top', 'Top');
    $row = $main->row('r');
    $inner = $row->column('inner');
    $grow = $inner->label('grow', 'Grow')->fill();
    $window->present();
    pumpFor(0.2);

    expect($row->size()[1])->toBeGreaterThan(250)
        ->and($grow->size()[1])->toBe($row->size()[1]);

    $grow->fill(false, false);
    pumpFor(0.1);
    expect($row->size()[1])->toBeLessThan(50)
        ->and($top->size()[1])->toBeLessThan(50);
});

it('aligns a child at the start, centre or end of its column, and keeps a minimum size', function (): void {
    // Rows, not labels: a stack's frame is its alignment rect, a label's sits 2pt outside it.
    $window = driver()->open('main', 300, 200);
    $main = $window->column('m', padding: 10);
    $start = $main->row('start')->align(Align::START)->minSize(20, 10);
    $center = $main->row('center')->align(Align::CENTER)->minSize(20, 10);
    $end = $main->row('end')->align(Align::END)->minSize(20, 10);
    $wide = $main->row('wide')->align(Align::START)->minSize(120, 30);
    $window->present();
    pumpFor(0.2);

    $frame = fn ($p) => $p->native()->frame();
    expect($frame($start)->x)->toBe(10.0)
        ->and(round($frame($center)->x + $frame($center)->width / 2))->toBe(150.0)
        ->and($frame($end)->x + $frame($end)->width)->toBe(290.0)
        ->and($wide->size())->toBe([120, 30]);

    $wide->minSize(40, 10);
    pumpFor(0.1);
    expect($wide->size())->toBe([40, 10]);
});

it('hides, paints and reorders children natively', function (): void {
    $window = driver()->open('main', 300, 200);
    $main = $window->column('m');
    $a = $main->label('a', 'A');
    $b = $main->label('b', 'B');
    $c = $main->label('c', 'C');
    $b->hide()->setBackground(Color::hex('#ff0000'));
    $c->moveBefore($a);

    expect($b->native()->isHidden())->toBeTrue()
        ->and(round($b->native()->layerBackgroundColor()->redComponent(), 2))->toBe(1.0)
        ->and($main->native()->arrangedSubviews())->toBe([$c->native(), $a->native(), $b->native()]);

    $b->show()->setBackground(null);
    $a->moveTo(2);
    expect($b->native()->isHidden())->toBeFalse()
        ->and($b->native()->layerBackgroundColor())->toBeNull()
        ->and($main->native()->arrangedSubviews())->toBe([$c->native(), $b->native(), $a->native()]);
});

it('removes a child from its stack and its native from the view tree', function (): void {
    $window = driver()->open('main', 300, 200);
    $main = $window->column('m');
    $a = $main->label('a', 'A');
    $b = $main->label('b', 'B');
    $native = $a->native();
    $a->remove();

    expect($main->native()->arrangedSubviews())->toBe([$b->native()])
        ->and($native->superview())->toBeNull()
        ->and($window->view('m.a'))->toBeNull()
        ->and(fn () => $a->setVisible(false))->toThrow(WindowException::class, 'removed');
});

it('keeps the window its size around a grid whose children do not fill', function (): void {
    $window = driver()->open('main', 400, 300);
    $grid = $window->grid('g', spacing: 2);
    $grid->at(0, 0, 1, 2)->label('a', 'A');
    $grid->at(1, 1)->button('b', 'B');
    $window->present();
    pumpFor(0.2);

    expect($window->size())->toBe([400, 300])
        ->and($grid->native()->frame()->width)->toBeLessThan(200.0)
        ->and($grid->native()->frame()->x)->toBe(0.0);

    $form = driver()->open('form', 400, 300);
    $main = $form->column('m');
    $main->label('title', 'Title');
    $fields = $main->grid('fields', spacing: 4);
    $fields->at(0, 0)->label('nl', 'Name');
    $fields->at(0, 1)->textInput('name');
    $form->present();
    pumpFor(0.2);

    expect($form->size())->toBe([400, 300])
        ->and($fields->native()->frame()->width)->toBeLessThan(300.0);

    $fields->view('name')->fill(true, false);
    pumpFor(0.1);
    expect($form->size())->toBe([400, 300])
        ->and($fields->native()->frame()->width)->toBe(400.0);
});

it('shares spare space equally between filling siblings', function (): void {
    $window = driver()->open('main', 300, 400);
    $main = $window->column('m');
    $a = $main->textArea('a')->fill();
    $b = $main->textArea('b')->fill();
    $c = $main->row('c')->fill();
    $window->present();
    pumpFor(0.2);

    [$ha, $hb, $hc] = [$a->size()[1], $b->size()[1], $c->size()[1]];
    expect(abs($ha - $hb))->toBeLessThanOrEqual(1)
        ->and(abs($hb - $hc))->toBeLessThanOrEqual(1)
        ->and($ha + $hb + $hc)->toBeGreaterThanOrEqual(398);

    $grid = driver()->open('grid', 400, 200)->grid('g');
    $left = $grid->at(0, 0)->textArea('l')->fill();
    $right = $grid->at(0, 1)->textArea('r')->fill();
    driver()->get('grid')->present();
    pumpFor(0.2);
    expect(abs($left->size()[0] - $right->size()[0]))->toBeLessThanOrEqual(1)
        ->and($left->size()[0])->toBeGreaterThan(150);
});

it('keeps a wrapping label readable inside containers that do not fill', function (): void {
    $window = driver()->open('main', 300, 200);
    $row = $window->column('m')->row('r');
    $inner = $row->column('inner');
    $label = $inner->label('l', 'Hello wrapping world')->setWrap(true);
    $window->present();
    pumpFor(0.2);

    expect($label->size()[0])->toBeGreaterThan(60)
        ->and($inner->size()[0])->toBeGreaterThanOrEqual($label->size()[0] - 4);
});

it('places a filling child by its main-axis align inside the space it takes', function (): void {
    $window = driver()->open('main', 300, 200);
    $row = $window->column('m')->row('r');
    $go = $row->button('go', 'Go')->fill(true, false)->align(Align::CENTER, Align::CENTER);
    $window->present();
    pumpFor(0.2);

    $frame = $go->native()->frame();
    expect($frame->width)->toBeLessThan(100.0)
        ->and(abs(($frame->x + $frame->width / 2) - 150.0))->toBeLessThanOrEqual(2.0)
        ->and($row->native()->arrangedSubviews())->toBe([$go->placedView()])
        ->and($go->placedView())->not->toBe($go->native());

    $go->align(Align::FILL, Align::CENTER);
    pumpFor(0.1);
    expect($go->placedView())->toBe($go->native())
        ->and($row->native()->arrangedSubviews())->toBe([$go->native()])
        ->and($go->native()->frame()->width)->toBeGreaterThan(250.0);
});

it('stops a hidden filling child from making its ancestors fill', function (): void {
    $window = driver()->open('main', 300, 300);
    $main = $window->column('m');
    $row = $main->row('r');
    $grow = $row->column('inner')->label('grow', 'Grow')->fill();
    $window->present();
    pumpFor(0.2);
    expect($row->size()[1])->toBeGreaterThan(250);

    $grow->hide();
    pumpFor(0.1);
    expect($row->size()[1])->toBeLessThan(50);
    $grow->show();
    pumpFor(0.1);
    expect($row->size()[1])->toBeGreaterThan(250);
});

it('frames a fixed child at least as large as what it holds requires', function (): void {
    $window = driver()->open('main', 300, 200);
    $fixed = $window->fixed('f');
    $box = $fixed->at(0, 0, 40, 20)->column('box');
    $wide = $box->row('wide')->minSize(100, 50);
    $window->present();
    pumpFor(0.2);

    expect($wide->size())->toBe([100, 50])
        ->and($box->size()[0])->toBeGreaterThanOrEqual(100);
});

it('removes a grid of spanning children without rebuilding it', function (): void {
    $window = driver()->open('main', 300, 200);
    $grid = $window->column('m')->grid('g');
    $grid->at(0, 0, 1, 2)->label('a', 'A');
    $grid->at(1, 0, 1, 2)->label('b', 'B');
    $native = $grid->native();
    $grid->remove();

    expect($grid->native())->toBe($native)
        ->and($native->superview())->toBe($grid->placedView())
        ->and($grid->placedView()->superview())->toBeNull();
});
