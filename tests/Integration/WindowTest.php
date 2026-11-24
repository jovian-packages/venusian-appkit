<?php

declare(strict_types=1);

use Jovian\Toolkits\Appkit\AppkitWindow;
use Surface\Contracts\Toolkits\Mail\Windows\WindowResized;

it('opens a native window under its name, titled with it', function (): void {
    $window = driver()->open('main', 400, 300);

    expect($window)->toBeInstanceOf(AppkitWindow::class)
        ->and($window->name())->toBe('main')
        ->and($window->isOpen())->toBeTrue()
        ->and($window->native())->toBeInstanceOf(NSWindow::class)
        ->and(nativeTitle($window))->toBe('main');
});

it('sets the native title', function (): void {
    $window = driver()->open('main', 400, 300)->setTitle('Main Window');

    expect(nativeTitle($window))->toBe('Main Window');
});

it('lays out its content area at the size asked for once presented', function (): void {
    $window = openWindow('main', 420, 280);

    expect($window->size())->toBe([420, 280]);
});

it('posts focus once when it becomes active', function (): void {
    $window = driver()->open('main', 400, 300);
    $window->present();

    $mail = requireActive($window);

    expect(countMail($mail, 'WindowFocused', 'main'))->toBe(1);
});

it('posts no resize for its first layout', function (): void {
    openWindow();
    pumpFor(0.2);

    expect(mailNames(takeMail()))->not->toContain('WindowResized');
});

it('posts the new size when the native window is resized', function (): void {
    $window = openWindow();
    takeMail();

    nativeResize($window, 520, 410);
    $resized = array_values(array_filter(mailUntil('WindowResized'), fn (object $m): bool => $m instanceof WindowResized));

    expect($resized)->not->toBeEmpty()
        ->and([end($resized)->window, end($resized)->width, end($resized)->height])->toBe(['main', 520, 410])
        ->and($window->size())->toBe([520, 410]);
});

it('refuses a second window under an open name', function (): void {
    driver()->open('main', 100, 100);

    expect(fn () => driver()->open('main', 100, 100))->toThrow(RuntimeException::class, 'already open');
});

it('closes on the user\'s close and frees its name', function (): void {
    $window = openWindow();
    $native = $window->native();
    takeMail();

    nativeUserClose($window);
    $mail = mailUntil('WindowClosed');

    expect($window->isOpen())->toBeFalse()
        ->and(nativeShown($native))->toBeFalse()
        ->and(mailNames($mail))->toContain('WindowClosed')
        ->and(fn () => $window->native())->toThrow(RuntimeException::class, 'closed')
        ->and(driver()->open('main', 100, 100))->not->toBe($window);
});

it('keeps the window and asks the app while it confirms close', function (): void {
    $window = openWindow()->confirmClose();
    takeMail();

    nativeUserClose($window);
    $mail = mailUntil('WindowCloseRequested');
    pumpFor(0.1);
    array_push($mail, ...takeMail());

    expect($window->isOpen())->toBeTrue()
        ->and(nativeShown($window->native()))->toBeTrue()
        ->and(mailNames($mail))->toContain('WindowCloseRequested')
        ->and(mailNames($mail))->not->toContain('WindowClosed');
});

it('closes from code while it confirms close', function (): void {
    $window = openWindow()->confirmClose();
    $native = $window->native();
    takeMail();

    $window->close();
    $mail = mailUntil('WindowClosed');

    expect($window->isOpen())->toBeFalse()
        ->and(nativeShown($native))->toBeFalse()
        ->and(mailNames($mail))->toContain('WindowClosed')
        ->and(mailNames($mail))->not->toContain('WindowCloseRequested');
});

it('closes once however many times it is closed', function (): void {
    $window = openWindow();
    takeMail();

    $window->close();
    $window->close();
    pumpFor(0.2);

    expect(countMail(takeMail(), 'WindowClosed', 'main'))->toBe(1);
});

it('closes a window that was never shown', function (): void {
    $window = driver()->open('main', 400, 300);

    $window->close();
    $mail = mailUntil('WindowClosed');

    expect($window->isOpen())->toBeFalse()
        ->and(mailNames($mail))->toContain('WindowClosed');
});

it('refuses its size once closed', function (): void {
    $window = openWindow();
    $window->close();

    expect(fn () => $window->size())->toThrow(RuntimeException::class, 'closed');
});
