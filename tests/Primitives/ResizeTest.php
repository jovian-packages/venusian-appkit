<?php

declare(strict_types=1);

use Surface\Contracts\Windows\Mail\View\ViewResized;

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

it('posts ViewResized only for watched views, coalesced', function (): void {
    $window = driver()->open('main', 400, 300);
    $main = $window->column('m');
    $watched = $main->label('w', 'W')->fill(vertical: false)->watchSize();
    $silent = $main->label('s', 'S')->fill(vertical: false);
    $window->present();
    pumpFor(0.2);
    takeMail(session());
    $window->native()->setContentSize(new NSSize(500.0, 300.0));
    $window->native()->setContentSize(new NSSize(520.0, 300.0));
    pumpFor(0.1);

    $resized = array_values(array_filter(takeMail(session()), fn ($m) => $m instanceof ViewResized));
    expect($resized)->toHaveCount(1)
        ->and($resized[0]->path())->toBe('m.w')
        ->and($resized[0]->width)->toBe($watched->size()[0])
        ->and($silent->size()[0])->toBe($watched->size()[0]);
    $watched->watchSize(false);
    $window->native()->setContentSize(new NSSize(540.0, 300.0));
    pumpFor(0.1);
    expect(array_filter(takeMail(session()), fn ($m) => $m instanceof ViewResized))->toBe([]);
});

it('drops a watched view\'s pending ViewResized when it is removed, and posts nothing after', function (): void {
    $window = driver()->open('main', 400, 300);
    $main = $window->column('m');
    $watched = $main->label('w', 'W')->watchSize();
    $window->present();
    pumpFor(0.2);
    takeMail(session());

    $window->native()->setContentSize(new NSSize(500.0, 300.0));
    $watched->remove();
    $window->native()->setContentSize(new NSSize(520.0, 300.0));
    pumpFor(0.1);

    expect(array_filter(takeMail(session()), fn ($m) => $m instanceof ViewResized))->toBe([]);
});
