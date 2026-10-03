<?php

declare(strict_types=1);

use Jovian\Toolkits\Appkit\Windows\AppkitWindow;
use Surface\Contracts\Windows\Mail\WindowClosed;
use Surface\Contracts\Windows\Mail\WindowFocused;
use Surface\Contracts\Windows\WindowException;

beforeEach(function (): void {
    driver()->closeAll();
    pumpFor(0.05);
    takeMail(session());
});

it('opens a titled window under its name', function (): void {
    $window = driver()->open('main', 480, 320);

    expect($window)->toBeInstanceOf(AppkitWindow::class)
        ->and($window->name())->toBe('main')
        ->and($window->title())->toBe('main')
        ->and($window->isOpen())->toBeTrue()
        ->and($window->native())->toBeInstanceOf(NSWindow::class)
        ->and($window->native()->contentView()->frame())->toEqual(new NSRect(0.0, 0.0, 480.0, 320.0))
        ->and(driver()->has('main'))->toBeTrue()
        ->and(driver()->get('main'))->toBe($window)
        ->and(driver()->all())->toBe(['main' => $window]);

    $window->setTitle('Main Window');
    expect($window->title())->toBe('Main Window');
});

it('refuses a second window under the same name', function (): void {
    driver()->open('main', 100, 100);

    expect(fn () => driver()->open('main', 100, 100))->toThrow(WindowException::class, 'already open');
});

it('posts WindowFocused when presented and key', function (): void {
    driver()->open('main', 320, 200)->present();
    pumpFor(0.3);

    expect(takeMail(session()))->toContainEqual(new WindowFocused('main'));
});

it('closes once, posts WindowClosed once, and is gone afterwards', function (): void {
    $window = driver()->open('main', 320, 200)->present();
    pumpFor(0.1);
    takeMail(session());

    $window->close();
    $window->close();

    expect(takeMail(session()))->toEqual([new WindowClosed('main')])
        ->and($window->isOpen())->toBeFalse()
        ->and(driver()->has('main'))->toBeFalse()
        ->and(fn () => $window->title())->toThrow(WindowException::class, 'closed')
        ->and(fn () => $window->native())->toThrow(WindowException::class);
});

it('posts WindowClosed when the user closes the window', function (): void {
    $window = driver()->open('main', 320, 200)->present();
    pumpFor(0.1);
    takeMail(session());

    $window->native()->performClose(null);

    expect(takeMail(session()))->toEqual([new WindowClosed('main')])
        ->and($window->isOpen())->toBeFalse();
});

it('closes every window', function (): void {
    driver()->open('a', 100, 100);
    driver()->open('b', 100, 100);

    driver()->closeAll();

    expect(driver()->all())->toBe([])
        ->and(takeMail(session()))->toEqual([new WindowClosed('a'), new WindowClosed('b')]);
});
