<?php

declare(strict_types=1);

use Surface\Contracts\Toolkits\Mail\Views\ViewResized;

/*
| A view that watches its size posts ViewResized as AppKit lays it out at a new size: the
| view's frame-change notification, which AppKit posts only when asked.
*/

/** @return list<ViewResized> */
function resizes(array $mail): array
{
    return array_values(array_filter($mail, fn (object $m): bool => $m instanceof ViewResized));
}

afterEach(fn () => closeAll());

it('posts the size a watched view is laid out at, and again when it changes', function (): void {
    $window = openWindow('main', 400, 300);
    $page = $window->column('root')->column('page')->fill();
    $page->watchSize();
    pumpFor(0.3);
    $first = resizes(takeMail());
    $firstSize = $page->size();

    nativeResize($window, 500, 350);
    pumpFor(0.3);
    $second = resizes(takeMail());

    expect($first)->not->toBe([])
        ->and(end($first)->path)->toBe($page->path())
        ->and([end($first)->width, end($first)->height])->toBe($firstSize)
        ->and($second)->not->toBe([])
        ->and([end($second)->width, end($second)->height])->toBe($page->size())
        ->and($page->size()[0])->toBe(500);
});

it('posts nothing for a view that is not watched, or once it is not watched any more', function (): void {
    $window = openWindow('main', 400, 300);
    $page = $window->column('root')->column('page')->fill();
    pumpFor(0.3);
    takeMail();

    nativeResize($window, 450, 300);
    pumpFor(0.3);
    expect(resizes(takeMail()))->toBe([]);

    $page->watchSize();
    pumpFor(0.2);
    $page->watchSize(false);
    takeMail();

    nativeResize($window, 520, 300);
    pumpFor(0.3);
    expect(resizes(takeMail()))->toBe([]);
});

it('hears the resizes of any kind of primitive', function (): void {
    $window = openWindow('main', 400, 300);
    $label = $window->column('root')->label('title', 'Title')->fill();
    $label->watchSize();
    pumpFor(0.3);
    takeMail();

    nativeResize($window, 520, 300);
    pumpFor(0.3);

    expect(resizes(takeMail()))->not->toBe([]);
});
