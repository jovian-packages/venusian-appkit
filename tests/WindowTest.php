<?php

declare(strict_types=1);

use Jovian\Toolkits\Appkit\Windows\AppkitWindow;
use Surface\Contracts\Windows\Mail\WindowCloseRequested;
use Surface\Contracts\Windows\Mail\WindowClosed;
use Surface\Contracts\Windows\Mail\WindowFocused;
use Surface\Contracts\Windows\Mail\WindowScaleChanged;
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

it('keeps a confirming window open on the user close and posts WindowCloseRequested', function (): void {
    $window = driver()->open('main', 320, 200)->present();
    pumpFor(0.1);
    takeMail(session());

    expect($window->confirmClose())->toBe($window)
        ->and($window->isConfirmingClose())->toBeTrue();

    $window->native()->performClose(null);
    expect(takeMail(session()))->toEqual([new WindowCloseRequested('main')])
        ->and($window->isOpen())->toBeTrue();

    $window->close();
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

it('posts WindowScaleChanged when the backing scale changes, and nothing for the same scale', function (): void {
    $window = driver()->open('main', 200, 100);
    $window->present();
    pumpFor(0.2);
    takeMail(session());

    // One display here: the handler runs with the factor unchanged, then with the last one posted set off by hand.
    $window->backingChanged();
    $same = array_filter(takeMail(session()), fn (object $mail): bool => $mail instanceof WindowScaleChanged);
    (fn () => $this->backing_scale = 0.5)->call($window);
    $window->backingChanged();

    expect($same)->toBe([])
        ->and(array_values(array_filter(takeMail(session()), fn (object $mail): bool => $mail instanceof WindowScaleChanged)))->toEqual([new WindowScaleChanged('main', $window->native()->backingScaleFactor())]);
    $window->close();
});
